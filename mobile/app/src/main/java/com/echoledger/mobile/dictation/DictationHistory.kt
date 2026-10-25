package com.echoledger.mobile.dictation

import android.content.Context
import com.echoledger.mobile.data.ApiJson
import java.io.File
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer

@Serializable
data class HistoryEntry(val at: String, val text: String)

/** The newest [LIMIT] dictations, newest first, kept in no-backup storage on this phone only (B14). */
class DictationHistory(private val file: File) {
    @Synchronized
    fun all(): List<HistoryEntry> = runCatching {
        ApiJson.decodeFromString(ListSerializer(HistoryEntry.serializer()), file.readText())
    }.getOrDefault(emptyList())

    @Synchronized
    fun add(at: String, text: String) {
        if (text.isBlank()) return
        save((listOf(HistoryEntry(at, text)) + all()).take(LIMIT))
    }

    @Synchronized
    fun clear() {
        file.delete()
    }

    private fun save(items: List<HistoryEntry>) {
        val tmp = File(file.parentFile, file.name + ".tmp")
        tmp.writeText(ApiJson.encodeToString(ListSerializer(HistoryEntry.serializer()), items))
        if (!tmp.renameTo(file)) {
            file.delete()
            tmp.renameTo(file)
        }
    }

    companion object {
        const val LIMIT = 50

        fun of(context: Context) = DictationHistory(File(context.noBackupFilesDir, "dictation_history.json"))
    }
}
