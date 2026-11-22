# Copy deck: the call choice (before, during and after a call)

One source for the phone and the desktop: the card, the pill menu, Settings, notifications, guides and the website use
these words and no others. Never "Keep" or "Skip" on their own: they don't say what happens. `%1$s` / `%1$d` are
placeholders; names, apps and numbers inside Urdu lines are isolated (FSI…PDI) as everywhere else.

| key | English | Urdu |
|---|---|---|
| transcribe | Transcribe | ٹرانسکرپٹ بنائیں |
| save_audio_only | Save audio only | صرف آڈیو محفوظ کریں |
| save_audio_all | Save all as audio only | سب کی صرف آڈیو محفوظ کریں |
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
| card_close_desktop | Close to keep audio only | صرف آڈیو رکھنے کے لیے بند کریں |
| card_first_hint | You can change this in Settings › After each call. | آپ اسے ترتیبات › ہر کال کے بعد میں بدل سکتے ہیں۔ |
| card_always | Always do this? | ہمیشہ یہی کریں؟ |
| card_always_yes | Yes | ہاں |
| done_transcribing | Transcribing… | ٹرانسکرپٹ بن رہا ہے… |
| done_audio | Saved as audio | آڈیو محفوظ ہو گئی |
| done_deleted | Deleted | حذف ہو گئی |
| grouped_audio | %1$d calls saved as audio | %1$d کالز کی آڈیو محفوظ ہو گئی |
| grouped_mixed | %1$d calls | %1$d کالز |
| grouped_review | Review | دیکھیں |
| quiet_saved | Saved as audio during quiet hours | خاموش اوقات میں آڈیو محفوظ ہوئی |
| block_screens | Block screenshots of EchoLedger | EchoLedger کے اسکرین شاٹس روکیں |
| block_screens_help | Off: you can take screenshots of the app at any time. On: EchoLedger's own screens can't be captured. | بند: آپ ایپ کے اسکرین شاٹس کسی بھی وقت لے سکتے ہیں۔ آن: EchoLedger کی اپنی اسکرینز کیپچر نہیں ہو سکتیں۔ |
| hide_live_words | Hide EchoLedger's live words from screenshots and screen shares | EchoLedger کے لائیو الفاظ اسکرین شاٹس اور اسکرین شیئر سے چھپائیں |
| hide_live_words_help | On: captions, Live Assist and Live Translate show as a black box in screenshots, screen recordings and screen shares. The recording pill and the after-call card always show. Off: everything shows. | آن: کیپشنز، لائیو اسسٹ اور لائیو ترجمہ اسکرین شاٹس، اسکرین ریکارڈنگ اور اسکرین شیئر میں کالا ڈبہ دکھتے ہیں۔ ریکارڈنگ پِل اور کال کے بعد کا کارڈ ہمیشہ دکھتے ہیں۔ بند: سب کچھ دکھتا ہے۔ |

Confirmations (one line at a time, 5 s, never two at once, never on top of the card):
- after Transcribe: `done_transcribing` + `undo`;
- after the 8 s or a swipe: `done_audio` + `transcribe` + `undo`;
- after Delete: `done_deleted` + `undo`.
