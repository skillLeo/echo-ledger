package com.echoledger.mobile.dictation

import android.content.Context
import android.view.inputmethod.InputMethodManager

/** Whether the EchoLedger voice keyboard is turned on in Android's keyboards (Settings shows it, v2.1 UX-16). */
object VoiceKeyboardState {
    fun isOn(context: Context): Boolean {
        val manager = context.getSystemService(InputMethodManager::class.java) ?: return false
        return runCatching {
            manager.enabledInputMethodList.any {
                it.packageName == context.packageName && it.serviceName == VoiceKeyboardService::class.java.name
            }
        }.getOrDefault(false)
    }
}
