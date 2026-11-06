# Real answers of the API

Each file is what the server really answers to one call, with tokens and signatures replaced. Three test suites use them:

- **Server:** `web/tests/Feature/Api/ApiSamplesTest.php` walks an app's day against the real routes and fails if an answer
  no longer has the shape of its file (the same keys, the same kinds of value). After a deliberate API change, run
  `UPDATE_API_SAMPLES=1 php vendor/bin/pest tests/Feature/Api/ApiSamplesTest.php` in `web/`.
- **Phone:** `mobile/app/src/test/java/com/echoledger/mobile/data/ApiSamplesTest.kt` reads every file with the phone's models
  and fails if a model waits for a field the server never sends.
- **Desktop:** `desktop/src-tauri/tests/api_samples.rs` reads the answers the desktop has typed models for.

The other direction, what the apps send, is checked by running each app's real client against a real, disposable server.
Both tests are skipped unless the server is named, and both change data, so use a throwaway database:

```
ECHOLEDGER_TEST_SERVER=http://127.0.0.1:8791 ECHOLEDGER_TEST_EMAIL=… ECHOLEDGER_TEST_PASSWORD=… \
  ./gradlew testFullDebugUnitTest --tests '*LiveServerContractTest'     # in mobile/
ECHOLEDGER_TEST_SERVER=… ECHOLEDGER_TEST_EMAIL=… ECHOLEDGER_TEST_PASSWORD=… \
  cargo test --test live_server                                         # in desktop/src-tauri/
```

They exist because test fakes are written by hand and repeat what their author believes: a wrong type passed all of them
and would have stopped every phone from signing in.
