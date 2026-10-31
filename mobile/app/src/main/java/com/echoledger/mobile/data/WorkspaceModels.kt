package com.echoledger.mobile.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// Client workspaces (since 1.4, docs/API.md "Client workspaces"): brief, memory, call notes, files and Ask.

@Serializable
data class WorkspaceResponse(val data: WorkspaceDto)

@Serializable
data class WorkspaceDto(
    val client: WorkspaceClientDto,
    val ai: AiBudgetDto = AiBudgetDto(),
    val brief: BriefDto = BriefDto(),
    val memory: List<MemoryItemDto> = emptyList(),
    val notes: List<CallNotesDto> = emptyList(),
    val files: List<ClientFileDto> = emptyList()
)

@Serializable
data class WorkspaceClientDto(val id: String, val name: String, @SerialName("ai_notes") val aiNotes: Boolean = true)

@Serializable
data class AiBudgetDto(
    val configured: Boolean = false,
    @SerialName("spent_usd") val spentUsd: Double = 0.0,
    @SerialName("budget_usd") val budgetUsd: Double = 5.0,
    @SerialName("client_spent_usd") val clientSpentUsd: Double = 0.0
)

@Serializable
data class BriefDto(
    val sections: BriefSections = BriefSections(),
    val edited: Boolean = false,
    val status: String = "ready",
    val error: String? = null
)

@Serializable
data class BriefSections(
    val goal: String = "",
    val scope: List<String> = emptyList(),
    val constraints: List<String> = emptyList(),
    val deadlines: List<String> = emptyList(),
    val budget: List<String> = emptyList(),
    val people: List<String> = emptyList(),
    val glossary: List<String> = emptyList()
) {
    /** The list sections with their headings, in the order they are shown. */
    fun lists(): List<Pair<String, List<String>>> = listOf(
        "Scope" to scope,
        "Constraints" to constraints,
        "Deadlines" to deadlines,
        "Budget" to budget,
        "People" to people,
        "Glossary" to glossary
    )

    val isEmpty: Boolean get() = goal.isBlank() && lists().all { it.second.isEmpty() }
}

@Serializable
data class BriefResponse(val data: BriefDto)

@Serializable
data class MemoryItemDto(
    val id: String,
    val kind: String,
    val text: String,
    @SerialName("happened_on") val happenedOn: String? = null,
    val pinned: Boolean = false,
    val source: MemorySourceDto = MemorySourceDto()
)

@Serializable
data class MemorySourceDto(val type: String = "user", val id: String? = null, @SerialName("at_ms") val atMs: Long? = null)

@Serializable
data class MemoryItemResponse(val data: MemoryItemDto)

@Serializable
data class CallNotesDto(
    val id: String,
    @SerialName("session_id") val sessionId: String,
    val status: String,
    val error: String? = null,
    val language: String = "en",
    val sections: List<NoteSectionDto> = emptyList(),
    val session: NoteSessionDto? = null
)

@Serializable
data class NoteSectionDto(val key: String, val heading: String, val items: List<NoteItemDto> = emptyList())

@Serializable
data class NoteItemDto(val text: String, val verify: Boolean = false, @SerialName("at_ms") val atMs: Long = 0, val kind: String? = null)

@Serializable
data class NoteSessionDto(
    val id: String,
    val title: String? = null,
    @SerialName("started_at") val startedAt: String? = null,
    @SerialName("duration_ms") val durationMs: Long? = null
)

@Serializable
data class CallNotesResponse(val data: CallNotesDto)

@Serializable
data class ClientFileDto(
    val id: String,
    val name: String,
    val mime: String = "",
    @SerialName("size_bytes") val sizeBytes: Long = 0,
    val pages: Int? = null,
    val status: String = "ready",
    val error: String? = null,
    val instructions: Boolean = false
)

@Serializable
data class ClientFileResponse(val data: ClientFileDto)

@Serializable
data class AskAnswerDto(val answer: String, val citations: List<CitationDto> = emptyList())

@Serializable
data class AskResponse(val data: AskAnswerDto)

@Serializable
data class CitationDto(
    val type: String,
    @SerialName("session_id") val sessionId: String? = null,
    @SerialName("file_id") val fileId: String? = null,
    val title: String = "",
    @SerialName("at_ms") val atMs: Long? = null,
    val page: Int? = null,
    val quote: String = ""
)

@Serializable
data class BundleDto(val text: String)

@Serializable
data class BundleResponse(val data: BundleDto)
