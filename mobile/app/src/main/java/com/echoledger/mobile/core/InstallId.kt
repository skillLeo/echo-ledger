package com.echoledger.mobile.core

import android.content.Context
import java.io.File
import java.util.UUID

/**
 * This install's own id (since v1.6, P3): made once, kept in the app's private no-backup storage (so a backup restored
 * on another phone gets its own), and sent with every sign-in, so signing in again reuses this phone's row on the
 * server instead of adding another. Not secret; never the IP address or a hardware id.
 */
object InstallId {
    private const val FILE = "install_id"

    @Synchronized
    fun get(context: Context): String {
        val file = File(context.noBackupFilesDir, FILE)
        file.takeIf { it.exists() }?.readText()?.trim()?.takeIf { valid(it) }?.let { return it }
        val made = UUID.randomUUID().toString()
        file.writeText(made)
        return made
    }

    fun valid(id: String): Boolean = runCatching { UUID.fromString(id) }.isSuccess && id.length == 36
}
