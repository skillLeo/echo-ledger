package com.echoledger.mobile.ui.v2.shell

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.MutableIntState
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.clearAndSetSemantics
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.MissedFix
import com.echoledger.mobile.core.Readiness
import com.echoledger.mobile.privilege.AppCallCapture
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.translate.TranslateMode
import com.echoledger.mobile.ui.ActivityBadge
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.activity.ActivityActions
import com.echoledger.mobile.ui.v2.activity.V2ActivityScreen
import com.echoledger.mobile.ui.v2.activity.activityBadge
import com.echoledger.mobile.ui.v2.ask.V2AskScreen
import com.echoledger.mobile.ui.v2.components.EchoBottomBar
import com.echoledger.mobile.ui.v2.components.EchoDestination
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.EchoToastState
import com.echoledger.mobile.ui.v2.help.HelpSource
import com.echoledger.mobile.ui.v2.home.HomeFacts
import com.echoledger.mobile.ui.v2.home.HomeModel
import com.echoledger.mobile.ui.v2.home.LibraryTop
import com.echoledger.mobile.ui.v2.home.LibraryTopActions
import com.echoledger.mobile.ui.v2.home.V2LibraryTop
import com.echoledger.mobile.ui.v2.home.setupShowsCapture
import com.echoledger.mobile.ui.v2.library.LibraryExtras
import com.echoledger.mobile.ui.v2.library.SavedView
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.library.V2TrashScreen
import com.echoledger.mobile.ui.v2.live.LiveEntry
import com.echoledger.mobile.ui.v2.live.V2LiveTab
import com.echoledger.mobile.ui.v2.settings.V2SettingsScreen
import com.echoledger.mobile.ui.v2.setup.V2SetupHealthScreen
import com.echoledger.mobile.ui.v2.setup.rememberSetupFacts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.theme.systemEchoLanguage
import com.echoledger.mobile.ui.v2.workspace.V2WorkspaceScreen
import com.echoledger.mobile.ui.v2.workspace.V2WorkspacesScreen
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch

/** The one feedback system of the v2 screens: every message and Undo goes through this toast host. */
val LocalEchoToasts = staticCompositionLocalOf<EchoToastState> { error("No EchoToastState: draw v2 screens inside EchoV2Shell") }

/** The destinations of the bottom bar (docs/PLAN_V2_PARITY.md section 2): Library · Projects · [Record] · Live · Ask. */
enum class V2Tab { Library, Projects, Live, Ask }

/** Where the shell is: a tab, or a page opened above the tabs. */
sealed interface V2Route {
    data object Tabs : V2Route
    data object Settings : V2Route

    /** Settings opened at one page (a recording's "Check your key"). */
    data class SettingsPage(val page: String) : V2Route
    data object ClassicSettings : V2Route
    data object Trash : V2Route
    data object Setup : V2Route
    data object Activity : V2Route
    data class ClassicPage(val id: String) : V2Route
    data class Live(val entry: LiveEntry) : V2Route

    /**
     * A recording; Back goes to [from] (the tabs, or the project page it was opened from); [atMs] plays from there.
     * [item] is one that is not in this phone's Library (a teammate's, from Shared with me).
     */
    data class Recording(
        val id: String,
        val from: V2Route = Tabs,
        val atMs: Long? = null,
        val item: com.echoledger.mobile.data.LibraryItem? = null
    ) : V2Route
    data object Projects : V2Route
    data class Project(val id: String) : V2Route
    data object ClassicProjects : V2Route

    /** A smart folder of Projects (No project, an app, Imported, Shared with me); Back goes to [from]. */
    data class Folder(val key: String, val from: V2Route = Tabs) : V2Route

    /** Request files; Back goes to [from]. */
    data class Requests(val from: V2Route = Tabs) : V2Route
}

/**
 * The classic screens the shell shows where the v2 one is not on yet (each `ui_v2.*` flag switches one area). The
 * caller wraps them in the classic theme.
 */
class ClassicScreens(
    val home: @Composable () -> Unit,
    val library: @Composable () -> Unit,
    val live: @Composable () -> Unit,
    val ask: @Composable () -> Unit,
    val settings: @Composable (onBack: () -> Unit) -> Unit,
    val upgrade: () -> Unit,
    /** One classic settings page by its SettingsIndex id (SettingsScreen with openPage), for Settings v2. */
    val settingsPage: @Composable (page: String, onBack: () -> Unit) -> Unit = { _, onBack -> settings(onBack) },
    /** The classic setup screens, until `ui_v2.setup_health` is on. */
    val setup: @Composable (onBack: () -> Unit) -> Unit = { onBack -> settings(onBack) },
    /**
     * What a Live tab row opens: the existing Record, voice note, Live Translate, Dictation and Captions screens, and
     * Live Assist's start (prep, Start now) once the phone core has it. Defaults to the classic Live tab.
     */
    val liveEntry: @Composable (entry: LiveEntry, onBack: () -> Unit) -> Unit = { _, _ -> live() }
)

/**
 * The v2 shell (behind `ui_v2.shell`): Library · Projects · [● Record] · Live · Ask in the bottom bar (Library first,
 * docs/PLAN_V2_PARITY.md section 2), Settings from the avatar of every main screen, Activity from the bell in Library's
 * header, and one toast host for every message (the view model's messages included). Library and the recording page
 * are the v2 ones when `ui_v2.library` is on; every other area shows its classic screen until its own flag is on.
 * Record: a tap records a voice note now ([onRecordNow]; false when the microphone isn't allowed, and the voice page
 * then asks), a long press opens the Record sheet. [onReadyCheck] opens the Ready check (true: turn auto-record on).
 * Settings › Appearance's text size and app language apply here.
 */
@Composable
fun EchoV2Shell(
    vm: MainViewModel,
    flags: UiFlags,
    darkTheme: Boolean,
    classic: ClassicScreens,
    onSwitchToClassic: (Boolean) -> Unit,
    modifier: Modifier = Modifier,
    language: EchoLanguage = systemEchoLanguage(),
    /** The wall clock toast deadlines and delayed discards are on (tests give their own). */
    clock: () -> Long = System::currentTimeMillis,
    /** Help v2's guides, notes and support messages (the phone core's); without it Help stays the classic page. */
    help: HelpSource? = null,
    onRecordNow: () -> Boolean = { false },
    onReadyCheck: (turnOn: Boolean) -> Unit = {},
    /** Goes up by one each time Activity should open (the after-call card's title: rename it or pick a project there). */
    openActivity: Int = 0,
    /** Goes up by one each time the Library should open (the after-call card's Review). */
    openLibrary: Int = 0
) {
    val settings by vm.settings.collectAsState()
    val shown = AppLook.language(AppLook.choice(settings), language)
    EchoV2Theme(darkTheme = darkTheme, language = shown, phoneFont = settings.phoneFont, wallpaperColours = settings.dynamicColour) {
        AppLook.TextSize(settings.textSize) {
            val toasts = remember { EchoToastState(clock) }
            // The bottom bar's height (0 on a page without it): toasts sit just above it.
            val bar = remember { mutableIntStateOf(0) }
            CompositionLocalProvider(LocalEchoToasts provides toasts) {
                MessagesToToasts(vm, toasts)
                CallLinesHost(toasts)
                Box(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
                    ShellBody(vm, flags, classic, onSwitchToClassic, help, onRecordNow, onReadyCheck, clock, openActivity, openLibrary, bar)
                    EchoToastHost(toasts, Modifier.align(Alignment.BottomCenter).aboveBar(bar.intValue))
                }
            }
        }
    }
}

/**
 * A v2 page shown outside the shell (a voice note shared from another app): the theme, the person's text size and
 * language, and the toast host, as the shell gives its pages.
 */
@Composable
fun EchoV2Frame(vm: MainViewModel, darkTheme: Boolean, language: EchoLanguage = systemEchoLanguage(), content: @Composable () -> Unit) {
    val settings by vm.settings.collectAsState()
    EchoV2Theme(
        darkTheme = darkTheme,
        language = AppLook.language(AppLook.choice(settings), language),
        phoneFont = settings.phoneFont,
        wallpaperColours = settings.dynamicColour
    ) {
        AppLook.TextSize(settings.textSize) {
            val toasts = remember { EchoToastState(System::currentTimeMillis) }
            CompositionLocalProvider(LocalEchoToasts provides toasts) {
                MessagesToToasts(vm, toasts)
                CallLinesHost(toasts)
                Box(Modifier.fillMaxSize().background(EchoV2.colors.bgCanvas).statusBarsPadding()) {
                    content()
                    EchoToastHost(toasts, Modifier.align(Alignment.BottomCenter).navigationBarsPadding())
                }
            }
        }
    }
}

/** Just above the bottom bar of [barPx] pixels; above the navigation bar on a page without it. */
@Composable
private fun Modifier.aboveBar(barPx: Int): Modifier =
    if (barPx > 0) padding(bottom = with(LocalDensity.current) { barPx.toDp() }) else navigationBarsPadding()

/** The view model's one-line messages ("Restored", "Offline …") become toasts, so there is one feedback system. */
@Composable
private fun MessagesToToasts(vm: MainViewModel, toasts: EchoToastState) {
    val message by vm.message.collectAsState()
    // Shown from a scope that outlives the effect: consuming the message restarts the effect.
    val scope = rememberCoroutineScope()
    val res = LocalContext.current.resources
    LaunchedEffect(message) {
        val text = message ?: return@LaunchedEffect
        vm.consumeMessage()
        scope.launch { toasts.show(VmMessages.localize(res, text)) }
    }
}

@Composable
private fun ShellBody(
    vm: MainViewModel,
    flags: UiFlags,
    classic: ClassicScreens,
    onSwitchToClassic: (Boolean) -> Unit,
    help: HelpSource?,
    onRecordNow: () -> Boolean,
    onReadyCheck: (Boolean) -> Unit,
    clock: () -> Long,
    openActivity: Int = 0,
    openLibrary: Int = 0,
    /** Set to the bottom bar's height while it shows, 0 while it doesn't. */
    bar: MutableIntState = mutableIntStateOf(0)
) {
    val text = v2Text
    val context = LocalContext.current
    var tab by rememberSaveable { mutableStateOf(V2Tab.Library) }
    var route by remember { mutableStateOf<V2Route>(V2Route.Tabs) }
    LaunchedEffect(openActivity) { if (openActivity > 0) route = V2Route.Activity }
    LaunchedEffect(openLibrary) {
        if (openLibrary > 0) {
            route = V2Route.Tabs
            tab = V2Tab.Library
        }
    }
    var libraryViews by remember { mutableStateOf(emptySet<SavedView>()) }
    val tabState = androidx.compose.runtime.saveable.rememberSaveableStateHolder()
    var reselected by remember { mutableIntStateOf(0) }
    var recordSheet by remember { mutableStateOf(false) }
    var serverUnread by remember { mutableIntStateOf(0) }
    val library by vm.library.collectAsState()
    val settings by vm.settings.collectAsState()
    val pending by vm.pending.collectAsState()
    // v2.1 UX-15: each opening of the app is counted once (not again on a rotation); from the second one, "Tap to record
    // · Hold for more" over Record, at most three times and never after the first long press.
    var openCounted by rememberSaveable { mutableStateOf(false) }
    var recordHint by rememberSaveable { mutableStateOf(false) }
    var hintAsked by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        if (!openCounted) {
            openCounted = true
            vm.updateSettings { it.copy(appOpens = it.appOpens + 1) }
        }
    }
    LaunchedEffect(settings.appOpens) {
        if (!hintAsked && RecordHint.due(settings)) {
            hintAsked = true
            recordHint = true
            vm.updateSettings { it.copy(recordHintShows = it.recordHintShows + 1) }
        }
    }
    LaunchedEffect(recordHint) {
        if (recordHint) {
            kotlinx.coroutines.delay(RecordHint.SHOW_MS)
            recordHint = false
        }
    }
    val missed by vm.missed.collectAsState()
    // Only whether a recording runs: the elapsed time ticks every second, and only the strip above the bar reads it.
    val recording by remember { RecordingService.live.map { it.active }.distinctUntilChanged() }
        .collectAsState(RecordingService.live.value.active)
    val translating by com.echoledger.mobile.translate.LiveTranslateService.state.collectAsState()
    val setup = rememberSetupFacts(vm, 0)
    val readiness = remember(setup) { Readiness.evaluate(setup.ready) }
    // The bell's server part: unread problems as the last Activity answer had them.
    LaunchedEffect(Unit) { vm.activity()?.let { serverUnread = ActivityBadge.server(it.data, vm.missed.value) } }
    // A project, Shared with me or request link opened in the app lands on the new pages while Projects is v2.
    val projectsTarget by vm.projectsTarget.collectAsState()
    LaunchedEffect(projectsTarget) {
        val t = projectsTarget ?: return@LaunchedEffect
        if (!flags.isOn(UiFlags.ASK)) return@LaunchedEffect
        route = when (t) {
            is com.echoledger.mobile.ui.ProjectsTarget.Project -> V2Route.Project(t.id)
            is com.echoledger.mobile.ui.ProjectsTarget.Folder -> V2Route.Folder(t.key)
            com.echoledger.mobile.ui.ProjectsTarget.Requests -> V2Route.Requests()
        }
        vm.openInProjects(null)
    }
    BackHandler(enabled = route != V2Route.Tabs) {
        route = when (val r = route) {
            V2Route.ClassicSettings -> V2Route.Settings
            is V2Route.Recording -> r.from
            is V2Route.Project -> V2Route.Projects
            is V2Route.Folder -> r.from
            is V2Route.Requests -> r.from
            V2Route.ClassicProjects -> if (flags.isOn(UiFlags.ASK)) V2Route.Tabs else V2Route.Projects
            else -> V2Route.Tabs
        }
    }
    val accountName by produceState<String?>(null) { value = vm.accountQuietly()?.name }
    val initials = initialsOf(accountName ?: settings.plan.workspace)
    fun open(entry: LiveEntry) {
        route = V2Route.Live(entry)
    }
    val guard = remember { RecordTapGuard(clock) }

    /**
     * The centre button, as classic's: idle, it starts a voice note now (the voice page asks for the microphone when
     * it isn't allowed); recording, it stops. A second tap soon after one that acted is ignored, whatever the recording
     * did in between, so a double tap never starts and stops at once.
     */
    fun recordNow() {
        if (!guard.tap()) return
        if (!onRecordNow()) open(LiveEntry.VoiceNote)
    }
    fun arm(on: Boolean) {
        runCatching {
            context.startForegroundService(
                RecordingService.intent(context, if (on) RecordingService.ACTION_ARM else RecordingService.ACTION_DISARM)
            )
        }
    }
    if (recordSheet) {
        val lock = settings.plan.lockFor("live_translate")
        V2RecordSheet(
            RecordSheetState(
                liveAssist = flags.isOn(UiFlags.LIVE_ASSIST),
                liveTranslate = settings.plan.liveTranslate,
                translateLocked = lock?.badge?.takeIf { !settings.plan.liveTranslate }
            ),
            RecordSheetActions(
                onRecordNow = ::recordNow,
                onVoiceNote = { open(LiveEntry.VoiceNote) },
                onImport = { open(LiveEntry.ImportAudio) },
                onLiveAssist = { open(LiveEntry.LiveAssist) },
                onDictation = { open(LiveEntry.Dictation) },
                // Game and video translate, with their state: the Live tab.
                onTranslate = {
                    route = V2Route.Tabs
                    tab = V2Tab.Live
                },
                onLocked = classic.upgrade
            ),
            onDismiss = { recordSheet = false }
        )
    }
    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        Box(Modifier.weight(1f).fillMaxWidth()) {
            CompositionLocalProvider(
                LocalAccountEntry provides AccountEntry(initials) { route = V2Route.Settings },
                LocalAvatarColour provides settings.avatarColor
            ) {
                when (val r = route) {
                    V2Route.Settings, is V2Route.SettingsPage -> {
                        val page = (r as? V2Route.SettingsPage)?.page
                        if (flags.settings) {
                            V2SettingsScreen(
                                vm = vm,
                                flags = flags,
                                onSwitchToClassic = onSwitchToClassic,
                                onUpgrade = classic.upgrade,
                                openClassicPage = classic.settingsPage,
                                setupPage = { highlight, back -> SetupPage(vm, flags, classic, highlight, back) },
                                onBack = { route = V2Route.Tabs },
                                help = help,
                                initialPage = page
                            )
                        } else if (page != null) {
                            classic.settingsPage(page) { route = V2Route.Tabs }
                        } else {
                            V2SettingsHome(
                                vm = vm,
                                flags = flags,
                                onSwitchToClassic = onSwitchToClassic,
                                onUpgrade = classic.upgrade,
                                onAllSettings = { route = V2Route.ClassicSettings },
                                onBack = { route = V2Route.Tabs }
                            )
                        }
                    }

                    V2Route.ClassicSettings -> classic.settings { route = V2Route.Settings }

                    V2Route.Setup -> SetupPage(vm, flags, classic, null) { route = V2Route.Tabs }

                    V2Route.Activity -> V2ActivityScreen(
                        vm,
                        ActivityActions(
                            onOpenRecording = { id -> if (flags.library) route = V2Route.Recording(id, from = V2Route.Activity) },
                            onFix = { fix ->
                                when (fix) {
                                    MissedFix.TEST -> route = V2Route.Setup
                                    MissedFix.PLANS -> classic.upgrade()
                                    MissedFix.TURN_ON -> onReadyCheck(true)
                                    else -> onReadyCheck(false)
                                }
                            },
                            onOpenUntranscribed = {
                                libraryViews = setOf(SavedView.Untranscribed)
                                tab = V2Tab.Library
                                route = V2Route.Tabs
                            },
                            onUnread = { serverUnread = it },
                            onBack = { route = V2Route.Tabs }
                        )
                    )

                    is V2Route.ClassicPage -> classic.settingsPage(r.id) { route = V2Route.Tabs }

                    // Game and Watch translate on the new components; the other entries open their screens.
                    is V2Route.Live -> when (r.entry) {
                        LiveEntry.LiveTranslate, LiveEntry.WatchTranslate -> {
                            val game = r.entry == LiveEntry.LiveTranslate
                            com.echoledger.mobile.ui.v2.live.V2LiveTranslatePage(
                                vm,
                                if (game) TranslateMode.GAME else TranslateMode.WATCH,
                                onBack = { route = V2Route.Tabs },
                                onSetup = { route = V2Route.Setup },
                                onSettings = { route = V2Route.SettingsPage(if (game) "games" else "videos") }
                            )
                        }

                        else -> classic.liveEntry(r.entry) { route = V2Route.Tabs }
                    }

                    V2Route.Projects -> V2WorkspacesScreen(
                        vm,
                        onOpen = { route = V2Route.Project(it) },
                        onFolder = { route = V2Route.Folder(it.key, from = V2Route.Projects) },
                        onRequests = { route = V2Route.Requests(from = V2Route.Projects) },
                        onBack = { route = V2Route.Tabs }
                    )

                    V2Route.ClassicProjects -> classic.ask()

                    is V2Route.Folder -> {
                        val folder = com.echoledger.mobile.ui.SmartFolder.of(r.key)
                        if (folder == null) {
                            LaunchedEffect(r.key) { route = r.from }
                        } else {
                            com.echoledger.mobile.ui.v2.workspace.V2FolderPage(
                                vm,
                                folder,
                                onBack = { route = r.from },
                                onOpenRecording = { item -> route = V2Route.Recording(item.id, from = r, item = item) }
                            )
                        }
                    }

                    is V2Route.Requests -> com.echoledger.mobile.ui.v2.workspace.V2RequestsPage(vm, onBack = { route = r.from })

                    is V2Route.Project -> V2WorkspaceScreen(
                        vm,
                        r.id,
                        onBack = {
                            val parent = vm.clients.value.firstOrNull { it.id == r.id }?.parentId
                            route = parent?.let { V2Route.Project(it) } ?: V2Route.Tabs
                        },
                        onOpenProject = { id -> route = id?.let { V2Route.Project(it) } ?: V2Route.Tabs },
                        onOpenRecording = { route = V2Route.Recording(it.id, from = r) },
                        onOpenAt = { id, at -> route = V2Route.Recording(id, from = r, atMs = at) }
                    )

                    V2Route.Trash -> V2TrashScreen(vm, configKeepHours = settings.trashKeepHours, onBack = { route = V2Route.Tabs })

                    is V2Route.Recording -> {
                        val item = library.firstOrNull { it.id == r.id } ?: r.item
                        if (item == null) {
                            LaunchedEffect(r.id) { route = V2Route.Tabs }
                        } else {
                            V2RecordingScreen(
                                vm,
                                item,
                                onBack = { route = r.from },
                                startAtMs = r.atMs,
                                onCaptureTest = { route = V2Route.Setup },
                                onOpenSettings = { page -> route = V2Route.SettingsPage(page) }
                            )
                        }
                    }

                    V2Route.Tabs -> when (tab) {
                        V2Tab.Library -> if (flags.library) {
                            // Worked out again only when what it reads changes (not each second of a recording).
                            val home = remember(readiness, setup, recording, library, pending, settings.transcriptionLanguage) {
                                HomeModel.state(
                                    HomeFacts(
                                        chip = readiness.chip,
                                        callsPossible = setup.callsPossible,
                                        captureReady = setup.helperReady,
                                        micAllowed = setup.ready.capabilities.micPermission,
                                        notificationsAllowed = setup.ready.capabilities.notificationPermission,
                                        live = com.echoledger.mobile.recording.LiveRecording(active = recording),
                                        library = library,
                                        pending = pending,
                                        transcriptionLanguage = settings.transcriptionLanguage,
                                        syncing = false,
                                        hasSynced = true,
                                        offline = false,
                                        usedMinutes = null,
                                        monthlyMinutes = null
                                    )
                                )
                            }
                            val badge = activityBadge(serverUnread, missed, pending)
                            val failedIds = remember(pending) { pending.filter { it.state == "failed" }.map { it.id } }
                            val extras = remember(
                                home,
                                readiness,
                                badge,
                                settings.setupCardHidden,
                                failedIds,
                                reselected,
                                settings.serverNotices,
                                settings.closedAnnouncements
                            ) {
                                LibraryExtras(
                                    actions = { ActivityBell(badge) { route = V2Route.Activity } },
                                    status = { CallsChip(readiness.chip, { onReadyCheck(false) }) },
                                    top = {
                                        V2LibraryTop(
                                            home.checklist,
                                            settings.setupCardHidden,
                                            LibraryTop.banner(
                                                readiness.chip,
                                                home.needsAction,
                                                setupShowsCapture(home.checklist, settings.setupCardHidden),
                                                readiness.problems.firstOrNull { it.blocking }?.key
                                            ),
                                            LibraryTopActions(
                                                onSetup = { route = V2Route.Setup },
                                                onAllow = { route = V2Route.Setup },
                                                onRecord = ::recordNow,
                                                onChooseLanguage = { route = V2Route.SettingsPage("language") },
                                                onHideSetup = { vm.updateSettings { it.copy(setupCardHidden = true) } },
                                                onFix = { onReadyCheck(false) },
                                                onReview = { route = V2Route.Activity },
                                                // Classic's Retry: each upload that failed, sent again.
                                                onRetryUploads = { failedIds.forEach(vm::retryUpload) },
                                                onUpdate = { openUpdate(context, vm, settings.serverNotices.appUpdate?.url) },
                                                onCloseAnnouncement = { vm.closeAnnouncement(it) }
                                            ),
                                            server = com.echoledger.mobile.core.ServerBanners.of(
                                                settings.serverNotices,
                                                settings.closedAnnouncements
                                            )
                                        )
                                    },
                                    scrollToTop = reselected
                                )
                            }
                            SideEffect { ShellStats.libraryCompositions++ }
                            // Library keeps where it was (its view, filters, tab and search) while a recording is open.
                            tabState.SaveableStateProvider(V2Tab.Library.name) {
                                V2LibraryScreen(
                                    vm = vm,
                                    initials = initials,
                                    onOpen = { route = V2Route.Recording(it.id) },
                                    onTrash = { route = V2Route.Trash },
                                    onAccount = { route = V2Route.Settings },
                                    initialViews = libraryViews,
                                    onOpenAt = { item, at -> route = V2Route.Recording(item.id, atMs = at) },
                                    extras = extras,
                                    onSharedWithMe = { route = V2Route.Folder(com.echoledger.mobile.ui.SmartFolder.SHARED.key) },
                                    onRequests = { route = V2Route.Requests() },
                                    onReviewWaiting = { route = V2Route.Activity }
                                )
                            }
                        } else {
                            Column(Modifier.fillMaxSize()) {
                                // Without v2 Library the bell and the avatar still lead to Activity and to Settings
                                // (where Switch back to classic is).
                                androidx.compose.foundation.layout.Row(
                                    Modifier.fillMaxWidth().padding(horizontal = com.echoledger.mobile.ui.v2.tokens.EchoTokens.Space.s4),
                                    horizontalArrangement = androidx.compose.foundation.layout.Arrangement.End,
                                    verticalAlignment = Alignment.CenterVertically
                                ) {
                                    ActivityBell(activityBadge(serverUnread, missed, pending)) { route = V2Route.Activity }
                                    AccountButton()
                                }
                                Box(Modifier.weight(1f).fillMaxWidth()) { classic.library() }
                            }
                        }

                        V2Tab.Projects -> if (flags.isOn(UiFlags.ASK)) {
                            V2WorkspacesScreen(
                                vm,
                                onOpen = { route = V2Route.Project(it) },
                                onFolder = { route = V2Route.Folder(it.key) },
                                onRequests = { route = V2Route.Requests() },
                                onBack = null
                            )
                        } else {
                            classic.ask()
                        }

                        V2Tab.Live -> if (flags.isOn(UiFlags.LIVE)) {
                            // Live Assist only for this person's flag, and only for sessions on this phone (no viewer role).
                            V2LiveTab(
                                liveAssist = flags.isOn(UiFlags.LIVE_ASSIST),
                                liveTranslate = true,
                                onOpen = ::open,
                                status = liveStatus(setup.ready.capabilities, settings.autoRecordArmed, translating, ::arm)
                            )
                        } else {
                            classic.live()
                        }

                        V2Tab.Ask -> if (flags.isOn(UiFlags.ASK)) {
                            // A citation opens its recording at that moment (the v2 page with ui_v2.library, else Library).
                            V2AskScreen(vm, onOpenAt = { id, at ->
                                if (flags.library) route = V2Route.Recording(id, atMs = at) else tab = V2Tab.Library
                            })
                        } else {
                            classic.ask()
                        }
                    }
                }
            }
        }
        if (route != V2Route.Tabs) SideEffect { bar.intValue = 0 }
        if (route == V2Route.Tabs) {
            Column(Modifier.onSizeChanged { bar.intValue = it.height }) {
                // While recording: the time and Stop above the bar, as classic's strip; a tap opens the voice page.
                if (recording) RecordingStrip(onStop = ::recordNow, onOpen = { open(LiveEntry.VoiceNote) })
                if (recordHint && !recording) RecordHintBubble { recordHint = false }
                EchoBottomBar(
                    destinations = listOf(
                        EchoDestination(V2Tab.Library.name, text.library, EchoIcons.Library),
                        EchoDestination(V2Tab.Projects.name, stringResource(R.string.v2_nav_projects), EchoIcons.Projects),
                        EchoDestination(V2Tab.Live.name, text.live, EchoIcons.Live),
                        EchoDestination(V2Tab.Ask.name, text.ask, EchoIcons.Ask)
                    ),
                    selectedKey = tab.name,
                    onSelect = { tab = V2Tab.valueOf(it) },
                    onReselect = { reselected++ },
                    centre = {
                        EchoRecordButton(recording, onTap = ::recordNow, onLongPress = {
                            recordHint = false
                            if (!settings.recordHintDone) vm.updateSettings { it.copy(recordHintDone = true) }
                            recordSheet = true
                        })
                    }
                )
            }
        }
    }
}

/**
 * Keeps the centre button from acting twice: after a tap that acted, further taps are ignored for [holdMs], whatever
 * the recording does meanwhile (the service shows a start within a few hundred milliseconds), so a double tap never
 * starts and stops at once.
 */
class RecordTapGuard(private val clock: () -> Long, private val holdMs: Long = 600) {
    private var actedAt: Long? = null

    /** Whether this tap may act. */
    fun tap(): Boolean {
        val now = clock()
        actedAt?.let { if (now - it < holdMs) return false }
        actedAt = now
        return true
    }
}

/** How often the shell drew Library (tests: a recording's ticking clock must not redraw it). */
@androidx.annotation.VisibleForTesting
internal object ShellStats {
    var libraryCompositions = 0
}

/** Settings › Setup & health: the v2 wizard and checks with `ui_v2.setup_health`, else the classic setup. */
@Composable
private fun SetupPage(vm: MainViewModel, flags: UiFlags, classic: ClassicScreens, highlight: String?, onBack: () -> Unit) {
    var classicSetup by remember { mutableStateOf(!flags.isOn(UiFlags.SETUP_HEALTH)) }
    if (classicSetup) {
        // Sign-in and the test call live on the classic setup screens until their own v2 pages exist.
        classic.setup { if (flags.isOn(UiFlags.SETUP_HEALTH)) classicSetup = false else onBack() }
    } else {
        V2SetupHealthScreen(vm, highlight, onClassicSetup = { classicSetup = true }, onBack = onBack)
    }
}

/**
 * A server banner's Update, as classic does it: the website build updates itself from the server; the store build opens
 * the page the server names.
 */
internal fun openUpdate(context: android.content.Context, vm: com.echoledger.mobile.ui.MainViewModel, url: String?) {
    if (com.echoledger.mobile.BuildConfig.CAPTURE_HELPER || url.isNullOrBlank()) {
        vm.checkForUpdates()
    } else {
        runCatching {
            context.startActivity(
                android.content.Intent(android.content.Intent.ACTION_VIEW, android.net.Uri.parse(url))
                    .addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)
            )
        }
    }
}

/** The Record hint (v2.1 UX-15): from the second opening of the app, at most three times, never after a long press. */
internal object RecordHint {
    const val SHOWS = 3
    const val SHOW_MS = 8_000L

    fun due(s: com.echoledger.mobile.data.AppSettings): Boolean = s.appOpens >= 2 && !s.recordHintDone && s.recordHintShows < SHOWS
}

/** "Tap to record · Hold for more", centred over Record; a tap closes it. */
@Composable
private fun RecordHintBubble(onClose: () -> Unit) {
    val c = EchoV2.colors
    // With TalkBack on, a tap is a double tap and a long press a double tap and hold: the hint says so in its words.
    val context = LocalContext.current
    val talkBack = remember {
        context.getSystemService(android.view.accessibility.AccessibilityManager::class.java)?.isTouchExplorationEnabled == true
    }
    val words = stringResource(if (talkBack) R.string.v2_rs_hint_talkback else R.string.v2_rs_hint)
    // Said once, never a place for TalkBack's focus: a double tap then always lands on Record, not on the hint.
    val view = androidx.compose.ui.platform.LocalView.current
    LaunchedEffect(words) { if (talkBack) view.announceForAccessibility(words) }
    Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
        androidx.compose.material3.Surface(
            onClick = onClose,
            modifier = Modifier
                .padding(bottom = com.echoledger.mobile.ui.v2.tokens.EchoTokens.Space.s8)
                .clearAndSetSemantics { this[RecordHintText] = words },
            shape = androidx.compose.foundation.shape.RoundedCornerShape(
                com.echoledger.mobile.ui.v2.tokens.EchoTokens.Component.menuRadius
            ),
            color = c.textPrimary,
            contentColor = c.bgSurface,
            shadowElevation = com.echoledger.mobile.ui.v2.tokens.EchoTokens.Elevation.floating
        ) {
            androidx.compose.material3.Text(
                words,
                style = EchoV2.type.meta,
                modifier = Modifier.padding(
                    horizontal = com.echoledger.mobile.ui.v2.tokens.EchoTokens.Space.s12,
                    vertical = com.echoledger.mobile.ui.v2.tokens.EchoTokens.Space.s8
                )
            )
        }
    }
}

/** The Record hint's words (tests read them here; TalkBack hears them once, announced, and never focuses the hint). */
internal val RecordHintText = androidx.compose.ui.semantics.SemanticsPropertyKey<String>("RecordHintText")

/** What the Live tab says of the calls and Live Translate (they left the New sheet, v2.1 UX-15). */
@Composable
private fun liveStatus(
    caps: com.echoledger.mobile.privilege.Capabilities,
    armed: Boolean,
    translating: com.echoledger.mobile.translate.TranslateMode?,
    onArm: (Boolean) -> Unit
): com.echoledger.mobile.ui.v2.live.LiveStatus {
    val context = LocalContext.current
    val calls = stringResource(
        when (caps.appCalls) {
            AppCallCapture.NOT_IN_THIS_BUILD -> R.string.v2_rs_calls_web
            AppCallCapture.NONE -> R.string.v2_rs_calls_android
            AppCallCapture.NEEDS_HELPER, AppCallCapture.VOICE_ONLY_NEEDS_HELPER -> R.string.v2_rs_calls_setup
            AppCallCapture.VOICE_ONLY -> R.string.v2_rs_calls_voice
            AppCallCapture.BOTH_SIDES -> if (armed) R.string.v2_rs_calls_armed else R.string.v2_rs_calls_ready
        }
    )
    val on = stringResource(R.string.v2_rs_on)
    val off = stringResource(R.string.v2_rs_off)
    val blocked = com.echoledger.mobile.translate.TranslateStatus.blocked(context)
    return com.echoledger.mobile.ui.v2.live.LiveStatus(
        armed = armed,
        canArm = caps.canCaptureCalls,
        calls = calls,
        onArm = onArm,
        game = if (translating == com.echoledger.mobile.translate.TranslateMode.GAME) {
            stringResource(R.string.v2_rs_game_status, on)
        } else {
            blocked ?: stringResource(R.string.v2_rs_game_status, off)
        },
        watch = if (translating == com.echoledger.mobile.translate.TranslateMode.WATCH) {
            stringResource(R.string.v2_rs_watch_status, on)
        } else {
            blocked ?: stringResource(R.string.v2_rs_watch_status, off)
        }
    )
}
