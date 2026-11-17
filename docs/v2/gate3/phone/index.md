# Gate 3: phone screens

Galaxy S25 size (1080 x 2340 px). Each screen below has `-light-en`, `-light-ur` and `-dark-en` pictures (many also
`-dark-ur`), drawn by the app's screenshot tests and recorded with `-Proborazzi.test.record=true`.

## Screens

- Home: `app/home-*.png`
- Home while recording: `app/home-recording-*.png`
- Library: `app/library-*.png`
- Trash: `app/trash-*.png`
- Recording: `app/recording-*.png`
- Recording, its screens: `app/recording-screens-*.png`
- Screen viewer: `app/screen-viewer-*.png`
- Ask: `app/ask-*.png`
- Ask, an answer: `app/ask-answer-*.png`
- Live Assist, start: `live-assist/start-screen-*.png`
- Live Assist, running: `live-assist/running-*.png`
- Live Assist, needs a recording: `live-assist/needs-recording-*.png`
- Live Assist, card on a mono call: `live-assist/card-mono-*.png`
- Recording pill: `live/pill-*.png`
- After a call: `live/after-call-*.png`
- Project workspace, Recordings: `app/workspace-recordings-*.png`
- Project workspace, Memory & instructions: `app/workspace-memory-*.png`
- Project workspace, Files: `app/workspace-files-*.png`
- Project workspace, Call notes: `app/workspace-call-*.png`
- Settings from the avatar: `app/settings-light-en.png`, `app/settings-light-ur.png`, `app/settings-dark-en.png` (and `-dark-ur`)
- Settings, first page: `app/settings-v2-*.png`
- Settings, search: `app/settings-v2-search-*.png`
- Help, guides: `app/help-guides-*.png`
- Help, a guide: `app/help-guide-*.png`
- Help, What's new: `app/help-new-*.png`
- Help, Contact support: `app/help-contact-*.png`

## Settings pages

- Calls & meetings: `app/settings-calls-*.png` (its Import dialog in Urdu: `app/settings-calls-import-dialog-light-ur.png`)
- Language: `app/settings-language-*.png`
- Custom words: `app/settings-words-*.png`
- Transcription key: `app/settings-key-*.png`
- App lock: `app/settings-lock-*.png`
- Privacy & data: `app/settings-privacy-*.png`
- Storage: `app/settings-storage-*.png`
- Notifications: `app/settings-notifications-*.png`
- Appearance: `app/settings-v2-appearance-*.png`
- Setup & health, all ready: `app/setup-ready-*.png`
- Setup & health, after a restart: `app/setup-after-restart-*.png`
- Microphone & audio, Voice notes, Live captions, Games, Videos, Plan & usage, Sign-in & security, Devices and About
  still open their classic page: no v2 picture yet.

## Also here

- `components/`: each v2 component, including at font sizes 1.3 and 2.0.
- `states/`: the empty, loading and error states of Home, Library, a recording and Settings.
- `library-*.png`, `recording-*.png` and `settings-*.png` at this folder's top: the first Gate 3 samples, before the
  screens ran on the app's data (the `app/` pictures above are the current ones).
- `live/assist-*.png`, `live/assist-states-*.png`, `live/live-tab-*.png`: Live Assist's card states and the Live tab,
  in English and Urdu.
