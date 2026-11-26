"""Compare Django and Laravel over HTTP using disposable databases."""
import argparse
import json
import os
import sys
from urllib.error import HTTPError
from urllib.request import Request, urlopen
from uuid import uuid4


def request(base, method, path, payload, token=None):
    headers = {"Accept": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    body = None
    if payload is not None:
        body = json.dumps(payload).encode()
        headers["Content-Type"] = "application/json"

    req = Request(base.rstrip("/") + "/" + path, data=body, headers=headers, method=method)
    try:
        response = urlopen(req, timeout=10)
    except HTTPError as error:
        response = error
    with response:
        raw = response.read()
        try:
            body = json.loads(raw) if raw else None
        except ValueError:
            body = "<non-JSON response>"
        return response.status, body


def normalize(value):
    if not isinstance(value, dict) or "data" not in value:
        return value

    def record(item):
        if not isinstance(item, dict):
            return item
        return {key: field for key, field in item.items() if key not in {"id", "createdAt", "updatedAt", "timestamp"}}

    data = value["data"]
    return {**value, "data": [record(item) for item in data] if isinstance(data, list) else record(data)}


def describe(value):
    return json.dumps(normalize(value), ensure_ascii=False, sort_keys=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("django")
    parser.add_argument("laravel")
    args = parser.parse_args()
    token = os.environ.get("HOMER_API_TOKEN")
    if not token:
        parser.error("Set HOMER_API_TOKEN for the Laravel instance.")

    matches = differences = 0

    def compare(label, method, path, payload=None, expected_limits=None):
        nonlocal matches, differences
        paths = (path, path) if isinstance(path, str) else path
        results = [
            request(args.django, method, paths[0], payload),
            request(args.laravel, method, paths[1], payload, token),
        ]
        left, right = results
        if label == "OpenAPI availability":
            left = (left[0], None)
            right = (right[0], None)
        normalized = [normalize(left[1]), normalize(right[1])]
        limits_match = expected_limits is None
        if expected_limits is not None and left[0] == right[0] == 200:
            actual_limits = tuple(item.get("meta", {}).get("limit") if isinstance(item, dict) else None for item in normalized)
            limits_match = actual_limits == expected_limits
            if limits_match:
                normalized = [{**item, "meta": {**item["meta"], "limit": None}} for item in normalized]
        if left[0] == right[0] and normalized[0] == normalized[1] and limits_match:
            matches += 1
            print(f"OK   {label} ({left[0]})")
        else:
            differences += 1
            print(f"DIFF {label}\n  Django  {left[0]} {describe(left[1])}\n  Laravel {right[0]} {describe(right[1])}")
        return left[1], right[1]

    def detail(resource, responses):
        paths = []
        for body in responses:
            data = body.get("data") if isinstance(body, dict) else None
            if isinstance(data, list):
                data = data[0] if data else None
            if not isinstance(data, dict) or "id" not in data:
                parser.exit(2, f"Cannot continue {resource} checks without an ID from both services.\n")
            paths.append(f"api/v1/{resource}/{data['id']}/")
        return tuple(paths)

    suffix = uuid4().hex[:12]
    phone = "+7000" + suffix
    channel = "contract-" + suffix
    event_id = "contract-" + suffix
    message_id = str(uuid4())
    expires = "2035-06-01T12:00:00.123456Z"
    scheduled = "2035-05-01T12:00:00Z"

    compare("Ping", "GET", "ping/")
    compare("Event log filter and empty page", "GET", f"api/v1/eventlog/?initiator={event_id}")
    compare("OpenAPI availability", "GET", "openapi/")

    recipient = compare("Recipient create", "POST", "api/v1/recipient/", {"phone": phone})
    compare("Recipient filter and pagination", "GET", f"api/v1/recipient/?phone=%2B7000{suffix}")
    compare("Recipient update", "PATCH", detail("recipient", recipient), {"name": "Contract test"})
    compare("Recipient validation", "POST", "api/v1/recipient/", {"email": "test@example.invalid"})

    compare("Channel create", "POST", "api/v1/channel/", {"code": channel, "name": "Contract SMS", "sender": "smsIdgtl", "config": {}})
    compare("Channel detail", "GET", f"api/v1/channel/{channel}/")
    compare("Channel update", "PATCH", f"api/v1/channel/{channel}/", {"name": "Contract updated"})

    message = {
        "uuid": message_id, "eventId": event_id, "recipientId": phone, "subject": event_id,
        "content": "Synthetic", "expire": expires, "mustBeSend": scheduled, "channels": [channel],
    }
    created = compare("Message create", "POST", "api/v1/message/", message)
    compare("Message duplicate event", "POST", "api/v1/message/", message)
    compare("Message detail", "GET", detail("message", created))
    compare("Message filter", "GET", f"api/v1/message/?eventId={event_id}")
    second_message = compare("Second message create", "POST", "api/v1/message/", {
        **message, "eventId": event_id + "-second", "uuid": str(uuid4()),
    })
    compare("Explicit page size (meta.limit: Django 100, Laravel 1)", "GET",
            f"api/v1/message/?subject={event_id}&limit=1", expected_limits=(100, 1))
    compare("Second message delete", "DELETE", detail("message", second_message))
    compare("Message metadata update", "PATCH", detail("message", created), {"metadata": {"empty": {}}})
    scenario = compare("Scenario create", "POST", "api/v1/scenario/", {
        "uuid": str(uuid4()), "title": "Synthetic", "messageType": 0, "description": "Synthetic",
        "channelsFirstOf": [{"channel": channel, "isActive": True, "fieldsConditions": {}}],
    })
    compare("Scenario update", "PATCH", detail("scenario", scenario), {"description": "Changed"})
    compare("Scenario delete", "DELETE", detail("scenario", scenario))
    without_recipient = {key: value for key, value in message.items() if key != "recipientId"}
    created_without_recipient = compare("Message without recipient", "POST", "api/v1/message/", {
        **without_recipient, "eventId": event_id + "-metadata", "uuid": str(uuid4()),
        "metadata": {"target": {"address": "test@example.invalid"}},
    })
    compare("Delete message without recipient", "DELETE", detail("message", created_without_recipient))

    sending = compare("Sending create", "POST", "api/v1/sending/", {
        "channel": channel, "uuid": message_id, "destination": phone,
        "content": "Synthetic", "expire": expires, "mustBeSend": scheduled,
    })
    compare("Sending update", "PATCH", detail("sending", sending), {"status": 3})
    compare("Sending callback", "PATCH", "api/v1/sending/status/", [{"messageUuid": message_id, "status": "delivered"}])
    compare("Sending delete", "DELETE", detail("sending", sending))
    compare("Message delete", "DELETE", detail("message", created))
    compare("Recipient delete", "DELETE", detail("recipient", recipient))
    compare("Channel delete", "DELETE", f"api/v1/channel/{channel}/")
    print(f"{matches} matched; {differences} differed")
    return 1 if differences else 0


if __name__ == "__main__":
    sys.exit(main())
