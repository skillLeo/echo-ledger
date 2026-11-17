package com.echoledger.mobile.ui.v2.shell

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.ask.V2AskScreen
import com.echoledger.mobile.ui.v2.components.EchoBottomBar
import com.echoledger.mobile.ui.v2.components.EchoDestination
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.EchoToastState
import com.echoledger.mobile.ui.v2.home.HomeActions
import com.echoledger.mobile.ui.v2.home.V2HomeScreen
import com.echoledger.mobile.ui.v2.library.SavedView
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.library.V2TrashScreen
import com.echoledger.mobile.ui.v2.live.LiveEntry
import com.echoledger.mobile.ui.v2.live.V2LiveTab
import com.echoledger.mobile.ui.v2.settings.V2SettingsScreen
import com.echoledger.mobile.ui.v2.setup.V2SetupHealthScreen
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.theme.systemEchoLanguage
import com.echoledger.mobile.ui.v2.workspace.V2WorkspaceScreen
import com.echoledger.mobile.ui.v2.workspace.V2WorkspacesScreen
import kotlinx.coroutines.launch

/** The one feedback system of the v2 screens: every message and Undo goes through this toast host. */
val LocalEchoToasts = staticCompositionLocalOf<EchoToastState> { error("No EchoToastState: draw v2 screens inside EchoV2Shell") }

/** The four destinations of the bottom bar (docs/v2/IA.md "Navigation per platform": Android). */
enum class V2Tab { Home, Library, Live, Ask }

/** Where the shell is: a tab, or a page opened above the tabs. */
sealed interface V2Route {
    data object Tabs : V2Route
    data object Settings : V2Route
    data object ClassicSettings : V2Route
    data object Trash : V2Route
    data object Setup : V2Route
    data class ClassicPage(val id: String) : V2Route
    data class Live(val entry: LiveEntry) : V2Route

    /** A recording; Back goes to [from] (the tabs, or the project page it was opened from). */
    data class Recording(val id: String, val from: V2Route = Tabs) : V2Route
    data object Projects : V2Route
    data class Project(val id: String) : V2Route
    data object ClassicProjects : V2Route
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
 * The v2 shell (behind `ui_v2.shell`): Home, Library, Live and Ask in the bottom bar, Settings from the avatar, and one
 * toast host for every message (the view model's messages included). Library and the recording page are the v2 ones
 * when `ui_v2.library` is on; every other area shows its classic screen until its own flag is on.
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
    clock: () -> Long = System::currentTimeMillis
) {
    EchoV2Theme(darkTheme = darkTheme, language = language) {
        val toasts = remember { EchoToastState(clock) }
        CompositionLocalProvider(LocalEchoToasts provides toasts) {
            MessagesToToasts(vm, toasts)
            Box(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
                ShellBody(vm, flags, classic, onSwitchToClassic)
                EchoToastHost(toasts, Modifier.align(Alignment.BottomCenter))
            }
        }
    }
}

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
private fun ShellBody(vm: MainViewModel, flags: UiFlags, classic: ClassicScreens, onSwitchToClassic: (Boolean) -> Unit) {
    val text = v2Text
    var tab by rememberSaveable { mutableStateOf(V2Tab.Library) }
    var route by remember { mutableStateOf<V2Route>(V2Route.Tabs) }
    var libraryViews by remember { mutableStateOf(emptySet<SavedView>()) }
    val library by vm.library.collectAsState()
    val settings by vm.settings.collectAsState()
    BackHandler(enabled = route != V2Route.Tabs) {
        route = when (val r = route) {
            V2Route.ClassicSettings -> V2Route.Settings
            is V2Route.Recording -> r.from
            is V2Route.Project -> V2Route.Projects
            V2Route.ClassicProjects -> V2Route.Projects
            else -> V2Route.Tabs
        }
    }
    val accountName by produceState<String?>(null) { value = vm.accountQuietly()?.name }
    val initials = initialsOf(accountName ?: settings.plan.workspace)
    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        Box(Modifier.weight(1f).fillMaxWidth()) {
            when (val r = route) {
                V2Route.Settings -> if (flags.settings) {
                    V2SettingsScreen(
                        vm = vm,
                        flags = flags,
                        onSwitchToClassic = onSwitchToClassic,
                        onUpgrade = classic.upgrade,
                        openClassicPage = classic.settingsPage,
                        setupPage = { highlight, back -> SetupPage(vm, flags, classic, highlight, back) },
                        onBack = { route = V2Route.Tabs }
                    )
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

                V2Route.ClassicSettings -> classic.settings { route = V2Route.Settings }

                V2Route.Setup -> SetupPage(vm, flags, classic, null) { route = V2Route.Tabs }

                is V2Route.ClassicPage -> classic.settingsPage(r.id) { route = V2Route.Tabs }

                is V2Route.Live -> classic.liveEntry(r.entry) { route = V2Route.Tabs }

                // The server's Trash time (contract 2.0.0-b, trash.keep_hours; 48 on an older server).
                V2Route.Projects -> V2WorkspacesScreen(
                    vm,
                    onOpen = { route = V2Route.Project(it) },
                    onSharedAndImported = { route = V2Route.ClassicProjects },
                    onBack = { route = V2Route.Tabs }
                )

                // Shared with me, imported and requests stay on today's Projects page.
                V2Route.ClassicProjects -> classic.ask()

                is V2Route.Project -> V2WorkspaceScreen(
                    vm,
                    r.id,
                    onBack = {
                        val parent = vm.clients.value.firstOrNull { it.id == r.id }?.parentId
                        route = parent?.let { V2Route.Project(it) } ?: V2Route.Projects
                    },
                    onOpenProject = { id -> route = id?.let { V2Route.Project(it) } ?: V2Route.Projects },
                    onOpenRecording = { route = V2Route.Recording(it.id, from = r) },
                    onOpenAt = { id, _ -> route = V2Route.Recording(id, from = r) }
                )

                V2Route.Trash -> V2TrashScreen(vm, configKeepHours = settings.trashKeepHours, onBack = { route = V2Route.Tabs })

                is V2Route.Recording -> {
                    val item = library.firstOrNull { it.id == r.id }
                    if (item == null) {
                        LaunchedEffect(r.id) { route = V2Route.Tabs }
                    } else {
                        V2RecordingScreen(vm, item, onBack = { route = r.from })
                    }
                }

                V2Route.Tabs -> when (tab) {
                    V2Tab.Home -> if (flags.isOn(UiFlags.HOME)) {
                        V2HomeScreen(
                            vm = vm,
                            initials = initials,
                            actions = HomeActions(
                                onSetup = { route = V2Route.Setup },
                                onAllow = { route = V2Route.Setup },
                                onRecord = { tab = V2Tab.Live },
                                onChooseLanguage = { route = V2Route.ClassicPage("language") },
                                onOpenRecording = { id -> if (flags.library) route = V2Route.Recording(id) else tab = V2Tab.Library },
                                onOpenUntranscribed = {
                                    libraryViews = setOf(SavedView.Untranscribed)
                                    tab = V2Tab.Library
                                },
                                onSeeAll = {
                                    libraryViews = emptySet()
                                    tab = V2Tab.Library
                                },
                                onPlan = { route = V2Route.ClassicPage("plan") },
                                onAccount = { route = V2Route.Settings }
                            )
                        )
                    } else {
                        classic.home()
                    }

                    V2Tab.Library -> if (flags.library) {
                        V2LibraryScreen(
                            vm = vm,
                            initials = initials,
                            onOpen = { route = V2Route.Recording(it.id) },
                            onTrash = { route = V2Route.Trash },
                            onAccount = { route = V2Route.Settings },
                            initialViews = libraryViews,
                            onProjects = if (flags.isOn(UiFlags.ASK)) ({ route = V2Route.Projects }) else null
                        )
                    } else {
                        classic.library()
                    }

                    V2Tab.Live -> if (flags.isOn(UiFlags.LIVE)) {
                        // Live Assist only for this person's flag, and only for sessions on this phone (no viewer role).
                        V2LiveTab(liveAssist = flags.isOn(UiFlags.LIVE_ASSIST), liveTranslate = true, onOpen = { route = V2Route.Live(it) })
                    } else {
                        classic.live()
                    }

                    V2Tab.Ask -> if (flags.isOn(UiFlags.ASK)) {
                        // A citation opens its recording (the v2 page with ui_v2.library, else the Library tab).
                        V2AskScreen(vm, onOpenAt = { id, _ -> if (flags.library) route = V2Route.Recording(id) else tab = V2Tab.Library })
                    } else {
                        classic.ask()
                    }
                }
            }
        }
        if (route == V2Route.Tabs) {
            EchoBottomBar(
                destinations = listOf(
                    EchoDestination(V2Tab.Home.name, text.home, EchoIcons.Home),
                    EchoDestination(V2Tab.Library.name, text.library, EchoIcons.Library),
                    EchoDestination(V2Tab.Live.name, text.live, EchoIcons.Live),
                    EchoDestination(V2Tab.Ask.name, text.ask, EchoIcons.Ask)
                ),
                selectedKey = tab.name,
                onSelect = { tab = V2Tab.valueOf(it) }
            )
        }
    }
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
