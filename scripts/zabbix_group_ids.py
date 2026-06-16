#!/usr/bin/env python3
"""Pull host group IDs from the Zabbix API.

Talks to the Zabbix JSON-RPC API (``hostgroup.get``) and prints each group's
id and name. Uses only the Python standard library, so no extra packages are
required.

Authentication uses an API token (Zabbix 5.4+). Create one under
  Administration -> General -> API tokens   (or per-user under your profile).

Configuration is read from CLI flags, falling back to environment variables:
  ZABBIX_URL    e.g. https://zabbix.example.org   (the script appends api_jsonrpc.php)
  ZABBIX_TOKEN  the API token string

Examples:
  # All groups, as a table
  ZABBIX_URL=https://zbx.local ZABBIX_TOKEN=abc... ./zabbix_group_ids.py

  # Only groups under the Site/ prefix (matches the closet inventory importer)
  ./zabbix_group_ids.py --url https://zbx.local --token abc... --prefix Site/

  # Machine-readable output
  ./zabbix_group_ids.py --json
"""
from __future__ import annotations

import argparse
import json
import os
import ssl
import sys
import urllib.error
import urllib.request


def call(url: str, token: str, method: str, params: dict,
         insecure: bool = False, timeout: float = 30.0) -> object:
    """Make a single Zabbix JSON-RPC call and return the ``result`` payload."""
    endpoint = url.rstrip("/")
    if not endpoint.endswith("api_jsonrpc.php"):
        endpoint += "/api_jsonrpc.php"

    payload = json.dumps({
        "jsonrpc": "2.0",
        "method": method,
        "params": params,
        "id": 1,
    }).encode("utf-8")

    request = urllib.request.Request(endpoint, data=payload, method="POST")
    request.add_header("Content-Type", "application/json-rpc")
    # Zabbix 6.4+ prefers the bearer header; older versions accept an "auth"
    # field in the body, but the header works across both when using a token.
    request.add_header("Authorization", f"Bearer {token}")

    context = None
    if insecure:
        context = ssl.create_default_context()
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE

    try:
        with urllib.request.urlopen(request, timeout=timeout, context=context) as resp:
            body = json.loads(resp.read().decode("utf-8"))
    except urllib.error.URLError as exc:
        raise SystemExit(f"error: could not reach Zabbix at {endpoint}: {exc}")

    if "error" in body:
        err = body["error"]
        raise SystemExit(
            f"error: Zabbix API {method} failed: "
            f"{err.get('message', '')} {err.get('data', '')}".strip()
        )
    return body.get("result")


def fetch_groups(url: str, token: str, prefix: str | None,
                 insecure: bool) -> list[dict]:
    params: dict = {"output": ["groupid", "name"], "sortfield": "name"}
    if prefix:
        # startsWith keeps it server-side; matches the PHP importer's Site/ walk.
        params["search"] = {"name": prefix}
        params["startSearch"] = True
    result = call(url, token, "hostgroup.get", params, insecure=insecure)
    return result or []


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Pull Zabbix host group IDs.")
    parser.add_argument("--url", default=os.environ.get("ZABBIX_URL"),
                        help="Zabbix base URL (or set ZABBIX_URL)")
    parser.add_argument("--token", default=os.environ.get("ZABBIX_TOKEN"),
                        help="Zabbix API token (or set ZABBIX_TOKEN)")
    parser.add_argument("--prefix", default=None,
                        help="Only return groups whose name starts with this prefix")
    parser.add_argument("--json", action="store_true", dest="as_json",
                        help="Emit JSON instead of a table")
    parser.add_argument("--insecure", action="store_true",
                        help="Skip TLS certificate verification")
    args = parser.parse_args(argv)

    if not args.url:
        parser.error("missing Zabbix URL (pass --url or set ZABBIX_URL)")
    if not args.token:
        parser.error("missing API token (pass --token or set ZABBIX_TOKEN)")

    groups = fetch_groups(args.url, args.token, args.prefix, args.insecure)

    if args.as_json:
        json.dump(groups, sys.stdout, indent=2)
        sys.stdout.write("\n")
        return 0

    if not groups:
        print("No host groups found.")
        return 0

    width = max(len(g["groupid"]) for g in groups)
    width = max(width, len("GROUPID"))
    print(f"{'GROUPID'.ljust(width)}  NAME")
    for g in groups:
        print(f"{g['groupid'].ljust(width)}  {g['name']}")
    print(f"\n{len(groups)} group(s).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
