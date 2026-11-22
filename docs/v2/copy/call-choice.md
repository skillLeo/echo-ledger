# Copy deck: the call choice (before, during and after a call)

One source for the phone and the desktop: the card, the pill menu, Settings, notifications, guides and the website use
these words and no others. Never "Keep" or "Skip" on their own: they don't say what happens. `%1$s` / `%1$d` are
placeholders; names, apps and numbers inside Urdu lines are isolated (FSI…PDI) as everywhere else.

| key | English | Urdu |
|---|---|---|
| transcribe | Transcribe | ٹرانسکرپٹ بنائیں |
| save_audio_only | Save audio only | صرف آڈیو محفوظ کریں |
| delete | Delete | حذف کریں |
| dont_record | Don't record | ریکارڈ نہ کریں |
| undo | Undo | واپس لیں |
| setting_title | After each call | ہر کال کے بعد |
| setting_ask | Ask me | مجھ سے پوچھیں |
| setting_ask_help | A small card after each call. | ہر کال کے بعد ایک چھوٹا کارڈ۔ |
| setting_transcribe | Transcribe every call | ہر کال کا ٹرانسکرپٹ بنائیں |
| setting_transcribe_help | No question. | کوئی سوال نہیں۔ |
| setting_audio | Save audio only | صرف آڈیو محفوظ کریں |
| setting_audio_help | No question. Transcribe later from the Library if you want. | کوئی سوال نہیں۔ چاہیں تو بعد میں لائبریری سے ٹرانسکرپٹ بنائیں۔ |
| setting_off | Don't record calls | کالز ریکارڈ نہ کریں |
| setting_off_help | Calls are not recorded. You can still record one from the pill or Record. | کالز ریکارڈ نہیں ہوں گی۔ پھر بھی پِل یا ریکارڈ سے کوئی کال ریکارڈ کر سکتے ہیں۔ |
| when_title | When to ask | کب پوچھیں |
| when_after | After the call (recommended) | کال کے بعد (تجویز کردہ) |
| when_start | At the start of the call | کال کے شروع میں |
| pill_transcribe | Transcribe this call | اس کال کا ٹرانسکرپٹ بنائیں |
| pill_audio | Save audio only | صرف آڈیو محفوظ کریں |
| pill_stop_delete | Stop and delete | روکیں اور حذف کریں |
| start_banner | Recording this call | یہ کال ریکارڈ ہو رہی ہے |
| card_meta | %1$s · just now | %1$s · ابھی |
| card_swipe | Swipe away to keep audio only | صرف آڈیو رکھنے کے لیے ہٹا دیں |
| card_first_hint | You can change this in Settings › After each call. | آپ اسے ترتیبات › ہر کال کے بعد میں بدل سکتے ہیں۔ |
| card_always | Always do this? | ہمیشہ یہی کریں؟ |
| card_always_yes | Yes | ہاں |
| done_transcribing | Transcribing… | ٹرانسکرپٹ بن رہا ہے… |
| done_audio | Saved as audio | آڈیو محفوظ ہو گئی |
| done_deleted | Deleted | حذف ہو گئی |
| grouped_audio | %1$d calls saved as audio | %1$d کالز کی آڈیو محفوظ ہو گئی |
| grouped_review | Review | دیکھیں |
| quiet_saved | Saved as audio during quiet hours | خاموش اوقات میں آڈیو محفوظ ہوئی |
| block_screens | Block screenshots of EchoLedger | EchoLedger کے اسکرین شاٹس روکیں |
| block_screens_help | Off: you can take screenshots at any time, also while recording. EchoLedger's windows still hide when you share your screen. | بند: آپ کسی بھی وقت اسکرین شاٹ لے سکتے ہیں، ریکارڈنگ کے دوران بھی۔ اسکرین شیئر کرتے وقت EchoLedger کی ونڈوز پھر بھی چھپ جاتی ہیں۔ |
| block_screens_help_old_android | On this Android version, EchoLedger's windows hide from screen sharing only while this is on. | اس اینڈرائیڈ ورژن پر EchoLedger کی ونڈوز اسکرین شیئر سے صرف تب چھپتی ہیں جب یہ آن ہو۔ |

Confirmations (one line at a time, 5 s, never two at once, never on top of the card):
- after Transcribe: `done_transcribing` + `undo`;
- after the 8 s or a swipe: `done_audio` + `transcribe` + `undo`;
- after Delete: `done_deleted` + `undo`.
