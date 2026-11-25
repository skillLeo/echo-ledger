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
import androidx.compose.ui.platform.LocalResources
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Problems
import com.echoledger.mobile.data.AskAnswerDto
import com.echoledger.mobile.data.CitationDto
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.ui.AskOutcome
import com.echoledger.mobile.ui.AskScope
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSegmented
import com.echoledger.mobile.ui.v2.components.EchoSelect
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.shell.VmMessages
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** Where an Ask stands: nothing asked yet, waiting, an answer, or it couldn't be made. */
sealed interface AskState {
    data object Idle : AskState
    data object Asking : AskState
    data class Answer(val answer: AskAnswerDto) : AskState

    /** [offline]: the phone had no connection; else the server's plain [words], when it gave any. */
    data class Failed(val offline: Boolean = false, val words: String? = null) : AskState
}

/** The core's catch-all failures: the screen says its own "The answer couldn't be made. Try again." instead. */
private val GENERIC = setOf(MainViewModel.ASK_FAILED, Problems.SERVER)

/** The answer's language (`language` on `POST /api/clients/{id}/ask`: en or roman_urdu). */
enum class AskLanguage(val wire: String) {
    English("en"),
    RomanUrdu("roman_urdu");

    companion object {
        /** From the phone's language: Roman Urdu on an Urdu phone (the server writes no Urdu script yet), else English. */
        fun from(language: EchoLanguage) = if (language == EchoLanguage.Urdu) RomanUrdu else English
    }
}

/** The words for a failed Ask: offline, nothing transcribed yet (409 no_transcript), the server's own, or ours. */
private fun failure(res: android.content.res.Resources, outcome: AskOutcome.Failed): AskState.Failed = AskState.Failed(
    offline = outcome.offline,
    words = when {
        outcome.code == "no_transcript" -> res.getString(R.string.v2_ask_nothing_yet)
        outcome.words in GENERIC -> null
        else -> VmMessages.localize(res, outcome.words)
    }
)

/** The scope "All my recordings" in the picker (`POST /api/ask`, since 2.0.0-o). */
const val EVERYWHERE = ""

/**
 * Ask v2 (behind `ui_v2.ask`, docs/v2/IA.md 4): pick what to ask about (all my recordings, or a project), ask in your own
 * words, get the answer with the recordings it comes from (each opens at its moment). `POST /api/ask` across all of the
 * person's recordings (since 2.0.0-o), `POST /api/clients/{id}/ask` for a project. On the view model's Ask and projects.
 */
@Composable
fun V2AskScreen(
    vm: MainViewModel,
    onOpenAt: (sessionId: String, atMs: Long) -> Unit,
    modifier: Modifier = Modifier,
    /** Ask inside a project's page: the scope is that project, with no picker and no page title. */
    fixedProject: ClientDto? = null
) {
    val all by vm.clients.collectAsState()
    val projects = fixedProject?.let { listOf(it) } ?: all
    // Ask across every recording first; a project's page asks that project.
    var selected by rememberSaveable { mutableStateOf(if (fixedProject == null) EVERYWHERE else fixedProject.id) }
    var question by rememberSaveable { mutableStateOf("") }
    var state by remember { mutableStateOf<AskState>(AskState.Idle) }
    // The language the app speaks (the account's while signed in), never the phone's own.
    val appLanguage = if (com.echoledger.mobile.ui.v2.account.appLocale().language == "ur") EchoLanguage.Urdu else EchoLanguage.English
    var answerIn by rememberSaveable { mutableStateOf(AskLanguage.from(appLanguage)) }
    val scope = rememberCoroutineScope()
    val res = LocalResources.current
    V2AskContent(
        projects = projects,
        selectedId = selected,
        onSelect = {
            // Another project: the answer on screen was about the last one.
            if (it != selected) state = AskState.Idle
            selected = it
        },
        question = question,
        onQuestion = { question = it },
        state = state,
        onAsk = { id ->
            state = AskState.Asking
            scope.launch {
                val target = if (id == EVERYWHERE) AskScope.Everywhere else AskScope.Project(id)
                when (val outcome = vm.askOutcome(target, question.trim(), answerIn.wire)) {
                    is AskOutcome.Answer -> state = AskState.Answer(outcome.answer)

                    // The connection sentence only when offline; else the core's plain words, or ours when they're generic.
                    is AskOutcome.Failed -> state = failure(res, outcome)
                }
            }
        },
        everywhere = fixedProject == null,
        onOpenAt = onOpenAt,
        answerIn = answerIn,
        onAnswerIn = { answerIn = it },
        modifier = modifier,
        inProject = fixedProject != null
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
    answerIn: AskLanguage = AskLanguage.English,
    onAnswerIn: (AskLanguage) -> Unit = {},
    modifier: Modifier = Modifier,
    /** Inside a project's page: no title, no scope picker (the project is the scope), and what answers use. */
    inProject: Boolean = false,
    /** "All my recordings" is the picker's first choice ([EVERYWHERE]; since 2.0.0-o). */
    everywhere: Boolean = false
) {
    val c = EchoV2.colors
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        if (!inProject) {
            EchoTopBar(stringResource(R.string.v2_ask_title), large = true) {
                com.echoledger.mobile.ui.v2.shell.AccountButton()
            }
        }
        if (projects.isEmpty() && !everywhere) {
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
                val all = ClientDto(EVERYWHERE, stringResource(R.string.v2_ask_everywhere))
                val choices = if (everywhere) listOf(all) + projects else projects
                val chosen = choices.firstOrNull { it.id == selectedId } ?: choices.first()
                if (inProject) {
                    Text(
                        stringResource(R.string.v2_ws_ask_footer, chosen.name),
                        style = EchoV2.type.caption,
                        color = c.textSecondary,
                        modifier = Modifier.padding(top = EchoTokens.Space.s12)
                    )
                } else {
                    EchoSelect(
                        label = stringResource(R.string.v2_ask_scope),
                        options = choices,
                        selected = chosen,
                        onSelect = { onSelect(it.id) },
                        optionLabel = { it.name }
                    )
                }
                EchoTextField(
                    value = question,
                    onValueChange = onQuestion,
                    label = stringResource(R.string.v2_ask_question),
                    placeholder = stringResource(R.string.v2_ask_question_hint),
                    singleLine = false
                )
                Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
                    Text(stringResource(R.string.v2_ask_answer_in), style = EchoV2.type.label, color = c.textPrimary)
                    EchoSegmented(
                        options = listOf(stringResource(R.string.v2_ask_lang_en), stringResource(R.string.v2_ask_lang_roman_urdu)),
                        selectedIndex = answerIn.ordinal,
                        onSelect = { onAnswerIn(AskLanguage.entries[it]) }
                    )
                }
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
                    stringResource(if (everywhere) R.string.v2_ask_empty_body_all else R.string.v2_ask_empty_body),
                    icon = EchoIcons.Ask
                )

                AskState.Asking -> EchoSkeletonList(3, avatar = false)

                is AskState.Failed -> EchoBanner(
                    when {
                        state.offline -> stringResource(R.string.v2_ask_offline)
                        state.words != null -> state.words
                        else -> stringResource(R.string.v2_ask_error)
                    },
                    EchoBannerKind.Error,
                    Modifier.padding(EchoTokens.Layout.pagePaddingMobile),
                    actionLabel = stringResource(R.string.v2_ask_ask_button),
                    onAction = { onAsk(selectedId ?: if (everywhere) EVERYWHERE else projects.first().id) }
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
            style = EchoV2.type.content(answer.answer, EchoV2.type.body),
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
        content = true,
        onClick = session?.let { { onOpenAt(it, citation.atMs ?: 0) } }
    )
}

/**
 * A recording's Ask tab (`POST /api/sessions/{id}/ask`, since 2.0.0-o): ask about this recording in your own words; the
 * answer with the moments it comes from, each playing from there ([onPlayFrom]); what the plan, the budget or a missing
 * transcript stops, said plainly.
 */
@Composable
fun V2AskRecording(vm: MainViewModel, sessionId: String, onPlayFrom: (Long) -> Unit, modifier: Modifier = Modifier) {
    var question by rememberSaveable(sessionId) { mutableStateOf("") }
    var state by remember(sessionId) { mutableStateOf<AskState>(AskState.Idle) }
    // The language the app speaks (the account's while signed in), never the phone's own.
    val appLanguage = if (com.echoledger.mobile.ui.v2.account.appLocale().language == "ur") EchoLanguage.Urdu else EchoLanguage.English
    var answerIn by rememberSaveable { mutableStateOf(AskLanguage.from(appLanguage)) }
    val scope = rememberCoroutineScope()
    val res = LocalResources.current
    val c = EchoV2.colors
    fun ask() {
        state = AskState.Asking
        scope.launch {
            when (val outcome = vm.askOutcome(AskScope.Recording(sessionId), question.trim(), answerIn.wire)) {
                is AskOutcome.Answer -> state = AskState.Answer(outcome.answer)
                is AskOutcome.Failed -> state = failure(res, outcome)
            }
        }
    }
    Column(modifier.fillMaxWidth()) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            Text(stringResource(R.string.v2_ask_recording_help), style = EchoV2.type.meta, color = c.textSecondary)
            EchoTextField(
                value = question,
                onValueChange = { question = it.take(500) },
                label = stringResource(R.string.v2_ask_question),
                placeholder = stringResource(R.string.v2_ask_recording_hint),
                singleLine = false
            )
            Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
                Text(stringResource(R.string.v2_ask_answer_in), style = EchoV2.type.label, color = c.textPrimary)
                EchoSegmented(
                    options = listOf(stringResource(R.string.v2_ask_lang_en), stringResource(R.string.v2_ask_lang_roman_urdu)),
                    selectedIndex = answerIn.ordinal,
                    onSelect = { answerIn = AskLanguage.entries[it] }
                )
            }
            EchoButton(
                stringResource(R.string.v2_ask_ask_button),
                ::ask,
                Modifier.fillMaxWidth(),
                enabled = question.trim().length >= 3,
                loading = state == AskState.Asking,
                loadingText = stringResource(R.string.v2_ask_asking)
            )
        }
        when (val st = state) {
            AskState.Idle -> Unit

            AskState.Asking -> EchoSkeletonList(2, avatar = false)

            is AskState.Failed -> EchoBanner(
                when {
                    st.offline -> stringResource(R.string.v2_ask_offline)
                    st.words != null -> st.words
                    else -> stringResource(R.string.v2_ask_error)
                },
                EchoBannerKind.Error,
                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                actionLabel = stringResource(R.string.v2_ask_ask_button),
                onAction = ::ask
            )

            // A source in this recording plays from its moment.
            is AskState.Answer -> AnswerPart(st.answer) { _, at -> onPlayFrom(at) }
        }
    }
}
