package com.echoledger.mobile.data

import java.io.File
import kotlinx.serialization.KSerializer
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * The answers the real server gives (docs/api-samples, kept true by the server's ApiSamplesTest) read with this app's own
 * models. The fakes in the other tests are written by hand and repeat what their author believes, so a wrong type survived
 * them: `workspace.id` as text would have stopped every sign-in.
 */
class ApiSamplesTest {
    private val dir = File("../../docs/api-samples")

    private val readBy: Map<String, KSerializer<*>> = mapOf(
        "account" to AccountDto.serializer(),
        "account_export" to AccountExportDto.serializer(),
        "activity" to ActivityResponse.serializer(),
        "activity_read" to UnreadAnswer.serializer(),
        "auth_options" to AuthOptionsDto.serializer(),
        "auth_start" to AuthStartDto.serializer(),
        "auth_start_new" to AuthStartDto.serializer(),
        "auth_code" to CodeSentDto.serializer(),
        "auth_code_verify_new" to SignUpNextDto.serializer(),
        "entitlements" to EntitlementsDto.serializer(),
        "transcription_key" to TranscriptionKeyDto.serializer(),
        "upgrade_request" to UpgradeRequestDto.serializer(),
        "upgrade_request_latest" to UpgradeRequestDto.serializer(),
        "auth_password" to RegisterResponse.serializer(),
        "bin" to BinResponse.serializer(),
        "client_brief" to BriefResponse.serializer(),
        "client_bundle" to BundleResponse.serializer(),
        "client_create" to ClientResponse.serializer(),
        "client_delete" to DeletedProjectAnswer.serializer(),
        "client_file" to ClientFileResponse.serializer(),
        "client_instructions" to ClientFileResponse.serializer(),
        "client_memory" to MemoryItemResponse.serializer(),
        "client_rule_create" to FilingRuleResponse.serializer(),
        "client_rules" to FilingRulesResponse.serializer(),
        "client_share_create" to ShareLinkDto.serializer(),
        "client_update" to ClientResponse.serializer(),
        "client_workspace" to WorkspaceResponse.serializer(),
        "clients" to ClientsResponse.serializer(),
        "config" to ServerConfig.serializer(),
        "library" to LibraryResponse.serializer(),
        "library_folder" to LibraryResponse.serializer(),
        "library_views" to LibraryViewsDto.serializer(),
        "live_translate_token" to LiveTranslateToken.serializer(),
        "recording_complete" to CompleteAnswer.serializer(),
        "recording_status" to RecordingUpload.serializer(),
        "request_link_create" to RequestLinkResponse.serializer(),
        "request_links" to RequestLinksResponse.serializer(),
        "search" to SearchResponse.serializer(),
        "security" to SecurityResponse.serializer(),
        "send" to SentAnswer.serializer(),
        "sent" to SentListResponse.serializer(),
        "session" to SessionDetailDto.serializer(),
        "session_create" to CreateSessionResponse.serializer(),
        "session_finish" to CreateSessionResponse.serializer(),
        "session_open" to CreateSessionResponse.serializer(),
        "session_screens" to ScreensResponse.serializer(),
        "sessions_bulk" to BulkAnswer.serializer(),
        "share_create" to ShareLinkDto.serializer(),
        "shared_save" to SavedSharedAnswer.serializer(),
        "shared_with_me" to SharedResponse.serializer(),
        "shares_by_client" to ShareListResponse.serializer(),
        "shares_list" to ShareListResponse.serializer(),
        "storage" to StorageDto.serializer(),
        "stt_token" to SttTokenResponse.serializer(),
        "transcript" to TranscriptDto.serializer(),
        "transcript_in_progress" to TranscriptDto.serializer(),
        "transcript_suggestion" to TranscriptDto.serializer(),
        "transcript_views" to TranscriptViewsDto.serializer(),
        "transcript_views_make" to TranscriptViewsDto.serializer(),
        "session_notes" to CallNotesResponse.serializer(),
        "session_notes_make" to CallNotesResponse.serializer(),
        "client_ask" to AskResponse.serializer()
    )

    /** Answers the phone sends for and does not read (only whether the call worked). */
    private val notRead =
        setOf("device_rename", "recording_chunk", "session_marker", "session_restore", "session_speakers", "session_update")

    private val everyField = Json {
        encodeDefaults = true
        explicitNulls = true
    }

    private fun text(name: String) = File(dir, "$name.json").readText()

    @Test
    fun `every sample has a model or is known not to be read`() {
        val files = dir.listFiles { f -> f.extension == "json" }!!.map { it.nameWithoutExtension }.toSet()
        assertEquals(emptySet<String>(), files - readBy.keys - notRead)
        assertEquals(emptySet<String>(), readBy.keys - files)
    }

    @Test
    fun `the phone reads every real answer`() {
        val failures = readBy.mapNotNull { (name, serializer) ->
            runCatching { ApiJson.decodeFromString(serializer, text(name)) }.exceptionOrNull()?.let { "$name: ${it.message?.take(240)}" }
        }
        assertEquals(failures.joinToString("\n"), 0, failures.size)
    }

    /** Fields a model declares that the answer never holds: a wrong name reads as a default for ever and says nothing. */
    private fun waiting(model: JsonElement, real: JsonElement?, at: String, out: MutableList<String>) {
        when (model) {
            is JsonObject -> {
                val obj = real as? JsonObject
                if (obj == null) return
                model.forEach { (key, value) ->
                    if (key !in obj) {
                        out += "$at.$key"
                    } else {
                        waiting(value, obj[key], "$at.$key", out)
                    }
                }
            }

            is JsonArray -> (real as? JsonArray)?.let { list ->
                if (model.isNotEmpty() && list.isNotEmpty()) waiting(model[0], list[0], "$at[0]", out)
            }

            else -> Unit
        }
    }

    /** What the real answers leave out on purpose: fields of another kind of item, or only in another answer. */
    private val notInEveryAnswer = setOf(
        // Limits that are not used up (how long recordings are kept, the longest recording) have no "used".
        "entitlements.limits.retention_days.used",
        "entitlements.limits.max_file_minutes.used",
        // A request just made has no answer yet: the latest request carries it.
        "upgrade_request.reply",
        "upgrade_request.decided_at",
        "auth_password.config.providers.live_captions.required_artifacts",
        "auth_password.config.providers.live_captions.preferred_primary",
        "auth_password.config.providers.dictation.required_artifacts",
        "auth_password.config.providers.dictation.preferred_primary",
        "auth_password.config.live_translate.watch.spoken",
        "config.providers.live_captions.required_artifacts",
        "config.providers.live_captions.preferred_primary",
        "config.providers.dictation.required_artifacts",
        "config.providers.dictation.preferred_primary",
        "config.live_translate.watch.spoken",
        // A filed client and a device share a model with the full client, which has a colour and a picture.
        "bin.data[0].client.color",
        "bin.data[0].client.avatar",
        "library_folder.data[0].device.color",
        "library_folder.data[0].device.avatar",
        "search.data[0].session.device.color",
        "search.data[0].session.device.avatar",
        "session_create.session.device.color",
        "session_create.session.device.avatar",
        "session_finish.session.device.color",
        "session_finish.session.device.avatar",
        "session_open.session.device.color",
        "session_open.session.device.avatar",
        // Only the page of one recording says who opened it.
        "search.data[0].session.opened",
        "session_create.session.opened",
        "session_finish.session.opened",
        "session_open.session.opened",
        "shared_with_me.data[0].session.opened",
        // A link's address exists only in the answer that made it: the server keeps a hash.
        "request_links.data[0].url",
        "shares_by_client.data[0].url",
        "shares_list.data[0].url",
        // A citation of a recording has no file or page; a note without a kind is a plain line; saving a recording is not a project.
        "client_ask.data.citations[0].file_id",
        "client_ask.data.citations[0].page",
        "session_notes.data.sections[0].items[0].kind",
        "shared_save.client_id"
    )

    @Test
    fun `no model waits for a field the server never sends`() {
        val out = mutableListOf<String>()
        @Suppress("UNCHECKED_CAST")
        readBy.forEach { (name, serializer) ->
            val s = serializer as KSerializer<Any?>
            val model = everyField.encodeToJsonElement(s, ApiJson.decodeFromString(s, text(name)))
            waiting(model, ApiJson.parseToJsonElement(text(name)), name, out)
        }
        assertEquals(emptySet<String>(), out.toSet() - notInEveryAnswer)
    }
}
