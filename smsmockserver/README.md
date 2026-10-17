SMSMockServer is technically an independent PHP webapp that mimics the APIs of SMS providers (currently 5CentSMS, Cellcast and MobileMessage). It is used for functional testing the Jethro SMS implementation.

SMSMockServer is mounted at:

 - http://localhost:8080/smsmockserver/ in the demo instance (see ../DEVELOPMENT_DEVBOX.md)
 - http://localhost:8089/smsmockserver/ in the functest instance (see ../DEVELOPMENT_FUNCTESTS.md)

 both via a nginx snippet in ../devbox.d/nginx/nginx.template:

```nginx
        location ~ ^/smsmockserver(/.*)?$ {
            root ../../../smsmockserver/webroot;
            include fastcgi.conf;
            fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
            fastcgi_param SCRIPT_NAME /smsmockserver/index.php;
            fastcgi_param PATH_INFO $1;
            fastcgi_pass php_backend;
        }
```

It is normally not mounted in a production Jethro, where SMS_TESTMODE=true is generally sufficient for making SMS temporarily present but non-functional.

## Recordings: keeping the mock faithful to the live APIs

`bin/record` replays a provider's API exchanges (`src/Recorder/<Provider>Cases.php`)
against any base URL and writes one pair of files per case:

- `<case>.json`: the request and reply **normalised** for diffing: keys sorted,
  lists reduced to their distinct item shapes, numbers to `<int>`, volatile
  strings to their format (`<uuid>`, `<Y-m-d H:i:s>`, `<msisdn 614…>`). Status
  codes, booleans and literal strings (statuses, error messages) are kept.
- `<case>.raw.json`: the reply as received, phone numbers masked.

Credentials come from the environment and are never written. A message a run
schedules is cancelled before it ends. Cases that would deliver an SMS live
under `send/` and run only with `--send`.

`recordings/<provider>/actual/` is the committed recording of the **live** API.
The `smsmock-recordings` functional test records the mock's
`recording-<provider>` profile (`tests/functional/smsmock/`) into
`recordings/<provider>/mockresults/` and fails unless it equals `actual/`
(raw files excluded). That profile mirrors the live account's state (senders,
unsubscribes), so list replies compare equal.

Re-record against the live API (by hand, e.g. before a release, or when a
provider announces API changes), review the diff, then fix the mock:

```bash
MOBILEMESSAGE_USERNAME=… MOBILEMESSAGE_PASSWORD=… MOBILEMESSAGE_TEST_NUMBER=04… \
  smsmockserver/bin/record mobilemessage https://api.mobilemessage.com.au smsmockserver/recordings/mobilemessage/actual
jj diff smsmockserver/recordings      # what the live API changed
```

`MOBILEMESSAGE_TEST_NUMBER` must be a number you own; it is also the sender
unless `MOBILEMESSAGE_TEST_SENDER` names another sender on the account. Without
`--send` nothing is delivered: the one send is scheduled a day ahead, then
cancelled. Only MobileMessage is recorded so far.
