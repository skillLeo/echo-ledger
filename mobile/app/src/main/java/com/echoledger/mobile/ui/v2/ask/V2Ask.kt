package com.echoledger.mobile.ui.v2.ask

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextDirection
import com.echoledger.mobile.R
import com.echoledger.mobile.data.AskAnswerDto
import com.echoledger.mobile.data.CitationDto
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSelect
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.systemEchoLanguage
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** Where an Ask stands: nothing asked yet, waiting, an answer, or it couldn't be made. */
sealed interface AskState {
    data object Idle : AskState
    data object Asking : AskState
    data class Answer(val answer: AskAnswerDto) : AskState
    data object Failed : AskState
}

/**
 * Ask v2 (behind `ui_v2.ask`, docs/v2/IA.md 4): pick what to ask about, ask in your own words, get the answer with the
 * recordings it comes from (each opens at its moment). The server answers per project today (`POST
 * /api/clients/{id}/ask`), so the scope is a project; "All recordings" is shown, disabled, with why. On the existing
 * view model's Ask and project list.
 */
@Composable
fun V2AskScreen(vm: MainViewModel, onOpenAt: (sessionId: String, atMs: Long) -> Unit, modifier: Modifier = Modifier) {
    val projects by vm.clients.collectAsState()
    var selected by rememberSaveable { mutableStateOf<String?>(null) }
    var question by rememberSaveable { mutableStateOf("") }
    var state by remember { mutableStateOf<AskState>(AskState.Idle) }
    val scope = rememberCoroutineScope()
    val language = if (systemEchoLanguage() == EchoLanguage.Urdu) "ur" else "en"
    V2AskContent(
        projects = projects,
        selectedId = selected ?: projects.firstOrNull()?.id,
        onSelect = { selected = it },
        question = question,
        onQuestion = { question = it },
        state = state,
        onAsk = { id ->
            state = AskState.Asking
            scope.launch {
                val answer = vm.ask(id, question.trim(), language)
                state = if (answer != null) AskState.Answer(answer) else AskState.Failed
            }
        },
        onOpenAt = onOpenAt,
        modifier = modifier
    )
}

/** Ask without the view model (tests and screenshots). */
@Composable
fun V2AskContent(
    projects: List<ClientDto>,
    selectedId: String?,
    onSelect: (String) -> Unit,
    question: String,
    onQuestion: (String) -> Unit,
    state: AskState,
    onAsk: (projectId: String) -> Unit,
    onOpenAt: (sessionId: String, atMs: Long) -> Unit,
    modifier: Modifier = Modifier
) {
    val c = EchoV2.colors
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_ask_title), large = true)
        if (projects.isEmpty()) {
            EchoEmptyState(
                stringResource(R.string.v2_ask_no_projects_title),
                stringResource(R.string.v2_ask_no_projects_body),
                icon = EchoIcons.Folder
            )
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Column(
                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
            ) {
                val chosen = projects.firstOrNull { it.id == selectedId } ?: projects.first()
                EchoSelect(
                    label = stringResource(R.string.v2_ask_scope),
                    options = projects,
                    selected = chosen,
                    onSelect = { onSelect(it.id) },
                    optionLabel = { it.name }
                )
                Text(stringResource(R.string.v2_ask_scope_all_soon), style = EchoV2.type.caption, color = c.textSecondary)
                EchoTextField(
                    value = question,
                    onValueChange = onQuestion,
                    label = stringResource(R.string.v2_ask_question),
                    placeholder = stringResource(R.string.v2_ask_question_hint),
                    singleLine = false
                )
                EchoButton(
                    stringResource(R.string.v2_ask_ask_button),
                    { onAsk(chosen.id) },
                    Modifier.fillMaxWidth(),
                    enabled = question.isNotBlank(),
                    loading = state == AskState.Asking,
                    loadingText = stringResource(R.string.v2_ask_asking)
                )
            }
            when (state) {
                AskState.Idle -> EchoEmptyState(
                    stringResource(R.string.v2_ask_empty_title),
                    stringResource(R.string.v2_ask_empty_body),
                    icon = EchoIcons.Ask
                )

                AskState.Asking -> EchoSkeletonList(3, avatar = false)

                AskState.Failed -> EchoBanner(
                    stringResource(R.string.v2_ask_error),
                    EchoBannerKind.Error,
                    Modifier.padding(EchoTokens.Layout.pagePaddingMobile),
                    actionLabel = stringResource(R.string.v2_ask_ask_button),
                    onAction = { onAsk(selectedId ?: projects.first().id) }
                )

                is AskState.Answer -> AnswerPart(state.answer, onOpenAt)
            }
        }
    }
}

@Composable
private fun AnswerPart(answer: AskAnswerDto, onOpenAt: (String, Long) -> Unit) {
    val c = EchoV2.colors
    Column(Modifier.semantics { liveRegion = LiveRegionMode.Polite }) {
        Text(
            answer.answer,
            style = EchoV2.type.body.copy(textDirection = TextDirection.Content),
            color = c.textPrimary,
            modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s16)
        )
        if (answer.citations.isNotEmpty()) {
            EchoSectionHeader(stringResource(R.string.v2_ask_sources))
            answer.citations.forEach { CitationRow(it, onOpenAt) }
        }
    }
}

@Composable
private fun CitationRow(citation: CitationDto, onOpenAt: (String, Long) -> Unit) {
    val session = citation.sessionId
    EchoSettingRow(
        citation.title,
        value = citation.atMs?.let { formatClock(it) },
        subtitle = citation.quote.takeIf { it.isNotBlank() },
        icon = if (citation.type == "file") EchoIcons.Folder else EchoIcons.Play,
        onClick = session?.let { { onOpenAt(it, citation.atMs ?: 0) } }
    )
}
