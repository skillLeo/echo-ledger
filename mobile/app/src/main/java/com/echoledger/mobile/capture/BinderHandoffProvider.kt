package com.echoledger.mobile.capture

import android.content.ContentProvider
import android.content.ContentValues
import android.database.Cursor
import android.net.Uri
import android.os.Binder
import android.os.Bundle
import android.os.Process
import com.echoledger.mobile.EchoLedgerApp

/**
 * Exported only so the ADB-launched helper (shell UID) can deliver its binder. Every call is UID-checked with
 * [CallerPolicy]: shell (2000), root (0) or this app — any other caller gets nothing and changes nothing.
 * No data is exposed (query/insert/update/delete are disabled).
 */
class BinderHandoffProvider : ContentProvider() {
    override fun onCreate(): Boolean = true

    override fun call(method: String, arg: String?, extras: Bundle?): Bundle? {
        val caller = Binder.getCallingUidOrThrow()
        if (!CallerPolicy.isAllowed(caller, Process.myUid())) {
            throw SecurityException("Caller $caller may not hand off a capture binder")
        }
        if (method != METHOD_ATTACH) return null
        val binder = extras?.getBinder(EXTRA_BINDER) ?: return null
        val app = context?.applicationContext as? EchoLedgerApp ?: return null
        app.graph.helper.attach(ICaptureHelper.Stub.asInterface(binder), source = "adb")
        return Bundle().apply { putBoolean("ok", true) }
    }

    override fun query(
        uri: Uri,
        projection: Array<out String>?,
        selection: String?,
        selectionArgs: Array<out String>?,
        sortOrder: String?
    ): Cursor? = null

    override fun getType(uri: Uri): String? = null

    override fun insert(uri: Uri, values: ContentValues?): Uri? = null

    override fun delete(uri: Uri, selection: String?, selectionArgs: Array<out String>?): Int = 0

    override fun update(uri: Uri, values: ContentValues?, selection: String?, selectionArgs: Array<out String>?): Int = 0

    companion object {
        const val METHOD_ATTACH = "attach"
        const val EXTRA_BINDER = "binder"
    }
}
