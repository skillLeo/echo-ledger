package com.echoledger.mobile.capture

/**
 * Who may hand a capture binder to the app (pure, unit-tested): only the shell user (ADB / Shizuku-via-ADB helper),
 * root (rooted Shizuku) or this app itself. Everything else — other apps included — is rejected.
 */
object CallerPolicy {
    const val ROOT_UID = 0
    const val SHELL_UID = 2000

    fun isAllowed(callingUid: Int, ownUid: Int): Boolean = callingUid == SHELL_UID || callingUid == ROOT_UID || callingUid == ownUid
}
