package com.echoledger.mobile

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.os.SystemClock
import androidx.activity.SystemBarStyle
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ExperimentalSharedTransitionApi
import androidx.compose.animation.SharedTransitionLayout
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.Folder
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material.icons.outlined.VideoLibrary
import androidx.compose.material.icons.rounded.AccountCircle
import androidx.compose.material.icons.rounded.Folder
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.VideoLibrary
import androidx.compose.material3.Icon
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.saveable.listSaver
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.net.toUri
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import androidx.lifecycle.lifecycleScope
import com.echoledger.mobile.core.AppLinks
import com.echoledger.mobile.core.Readiness
import com.echoledger.mobile.core.ReadyFix
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.recording.ReadinessWatch
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.ui.ActivityBadge
import com.echoledger.mobile.ui.ActivityScreen
import com.echoledger.mobile.ui.AppLockMachine
import com.echoledger.mobile.ui.Brand
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.LibraryScreen
import com.echoledger.mobile.ui.LocalUnlockPrompter
import com.echoledger.mobile.ui.LockInput
import com.echoledger.mobile.ui.LockScreen
import com.echoledger.mobile.ui.LockState
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.Method
import com.echoledger.mobile.ui.Motion
import com.echoledger.mobile.ui.OnboardingScreen
import com.echoledger.mobile.ui.PairingScreen
import com.echoledger.mobile.ui.ProjectsScreen
import com.echoledger.mobile.ui.ProjectsTarget
import com.echoledger.mobile.ui.ReadyCheckSheet
import com.echoledger.mobile.ui.RecordMode
import com.echoledger.mobile.ui.RecordScreen
import com.echoledger.mobile.ui.RecordSheet
import com.echoledger.mobile.ui.SessionScreen
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.SettingsState
import com.echoledger.mobile.ui.SetupScreen
import com.echoledger.mobile.ui.SharedNotesScreen
import com.echoledger.mobile.ui.SmartFolder
import com.echoledger.mobile.ui.StartupSplash
import com.echoledger.mobile.ui.UnlockPrompter
import com.echoledger.mobile.ui.WaitingScreen
import com.echoledger.mobile.ui.applySecureScreen
import com.echoledger.mobile.ui.capabilities
import com.echoledger.mobile.ui.design.EchoBottomBar
import com.echoledger.mobile.ui.design.LocalSharedScopes
import com.echoledger.mobile.ui.design.NavTab
import com.echoledger.mobile.ui.design.RecordingStrip
import com.echoledger.mobile.ui.design.tabDirection
import com.echoledger.mobile.ui.design.tabTransition
import com.echoledger.mobile.ui.formatDuration
import com.echoledger.mobile.ui.isDarkTheme
import com.echoledger.mobile.ui.lockAvailability
import com.echoledger.mobile.ui.promptUnlock
import com.echoledger.mobile.ui.reconnectUnlockPrompt
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.Design
import com.echoledger.mobile.ui.v2.shell.DesignChoice
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.UiFlagLatch
import com.echoledger.mobile.ui.v2.shell.UiFlags
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class MainActivity : FragmentActivity() {
    private val vm: MainViewModel by viewModels()

    /** A screen the launching intent asks for (`open = "pending"`: the Record tab with its pending list). */
    private val openRequest = mutableStateOf<String?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        if (savedInstanceState == null) openRequest.value = intent?.getStringExtra(EXTRA_OPEN) ?: intent?.let(::linkRequest)
        if (savedInstanceState == null) signedIn(intent)
        // Content hidden in Recents and screenshots while the app lock and its "hide content" option are on.
        lifecycleScope.launch {
            vm.settingsState.collect { state ->
                if (state is SettingsState.Loaded) window.applySecureScreen(state.settings.appLockEnabled && state.settings.secureScreen)
            }
        }
        setContent {
            val state by vm.settingsState.collectAsState()
            val settings = (state as? SettingsState.Loaded)?.settings
            val themeMode = settings?.themeMode ?: "system"
            val dark = isDarkTheme(themeMode)
            // Status and navigation bar icons follow the app's theme, not only the phone's (A6.M10).
            LaunchedEffect(dark) {
                val transparent = android.graphics.Color.TRANSPARENT
                val style = if (dark) SystemBarStyle.dark(transparent) else SystemBarStyle.light(transparent, transparent)
                enableEdgeToEdge(statusBarStyle = style, navigationBarStyle = style)
            }
            EchoLedgerTheme(themeMode, dynamicColour = settings?.dynamicColour == true, phoneFont = settings?.phoneFont == true) {
                Content()
            }
        }
    }

    override fun onStart() {
        super.onStart()
        // Auto-record that is on but not armed (Android did not deliver the update or boot broadcast) comes back as soon
        // as the app is opened, instead of staying off until the switch is touched.
        lifecycleScope.launch {
            val settings = (application as EchoLedgerApp).graph.settings.current()
            if (settings.autoRecordArmed && !RecordingService.isArmed) {
                runCatching {
                    startForegroundService(RecordingService.intent(this@MainActivity, RecordingService.ACTION_ARM, from = "app opened"))
                }
            }
        }
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        // The clipboard can be read again: a copied secret that came due while the app was behind is cleared now.
        if (hasFocus) com.echoledger.mobile.account.SecretClip.sweep(this)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        (intent.getStringExtra(EXTRA_OPEN) ?: linkRequest(intent))?.let { openRequest.value = it }
        signedIn(intent)
    }

    /** The browser sign-in came back (echoledger://signed-in?code=…&state=…, since v1.5). */
    private fun signedIn(intent: Intent?) {
        com.echoledger.mobile.core.SignIn.callback(intent?.data)?.let { (code, state) -> vm.completeBrowserSignIn(code, state) }
    }

    /** A share, project or request link opened in the app (since v1.5), as an [openRequest]; null for any other intent. */
    private fun linkRequest(intent: Intent): String? = AppLinks.link(intent.action, intent.data)?.let { OPEN_LINK_PREFIX + it }

    /** A link the app cannot open itself goes to the browser, never back here (an App Link would loop). */
    private fun openInBrowser(link: String) {
        val view = Intent(Intent.ACTION_VIEW, android.net.Uri.parse(link)).addCategory(Intent.CATEGORY_BROWSABLE)
        val chooser = Intent.createChooser(view, null)
            .putExtra(Intent.EXTRA_EXCLUDE_COMPONENTS, arrayOf(android.content.ComponentName(this, MainActivity::class.java)))
        runCatching { startActivity(chooser) }
    }

    @OptIn(ExperimentalSharedTransitionApi::class)
    @Composable
    private fun Content() {
        val settingsState by vm.settingsState.collectAsState()
        val loaded = settingsState is SettingsState.Loaded
        val settings = (settingsState as? SettingsState.Loaded)?.settings ?: AppSettings()
        val currentSettings by rememberUpdatedState(settings)
        val helper by vm.helper.collectAsState()
        val message by vm.message.collectAsState()
        // Since v1.6.1: the messages at the bottom follow docs/DESIGN_SYSTEM.md "Notices" (tap, ×, timer, swipe).
        val notices = remember { com.echoledger.mobile.ui.design.NoticeHostState() }
        // Where the user is survives a rotation (A6.M9): the tab, the open recording (by id) and the setup page.
        var tab by rememberSaveable { mutableStateOf(TAB_LIBRARY) }
        // Re-tapping the current tab scrolls it to the top (a counter each screen watches).
        var reselected by remember { mutableIntStateOf(0) }
        // The bottom bar hides while recordings are selected in the Library.
        var librarySelecting by remember { mutableStateOf(false) }
        // The centre button opens the Record sheet; its choice decides what the Record screen shows.
        var recordMode by rememberSaveable { mutableStateOf(RecordMode.VOICE) }
        var recordSheet by remember { mutableStateOf(false) }
        var activityUnread by rememberSaveable { mutableIntStateOf(0) }
        val live by RecordingService.live.collectAsState()
        val pending by vm.pending.collectAsState()
        val missed by vm.missed.collectAsState()

        /** A Settings page to open (since v1.8: a missed recording's "See plans"). */
        var settingsPage by remember { mutableStateOf<String?>(null) }
        var openItemId by rememberSaveable { mutableStateOf<String?>(null) }
        var openItemCache by remember { mutableStateOf<LibraryItem?>(null) }
        var openAtMs by rememberSaveable { mutableStateOf<Long?>(null) }
        var openSession by remember { mutableStateOf<String?>(null) }
        // A recording a link resolved to, while it is not in this phone's library yet (see the fallback below).
        var linkSession by remember { mutableStateOf<String?>(null) }
        var showSetup by rememberSaveable { mutableStateOf(false) }
        // Waiting for you (since 1.3.2): every call waiting for Keep or Discard on one screen.
        var showWaiting by rememberSaveable { mutableStateOf(false) }
        // In the v2 shell the calls waiting for Keep or Discard are in Activity (with Rename and Project there).
        var shellActivity by rememberSaveable { mutableIntStateOf(0) }

        // The tab a Record page was opened from through the New sheet: Back goes there (since v1.8.1, bug P-026).
        var recordReturnTab by rememberSaveable { mutableStateOf<String?>(null) }
        // Voice notes just shared from another app (local ids, comma-separated): their page, until Back leaves the app.
        var sharedNotes by rememberSaveable { mutableStateOf<String?>(null) }
        var sharedFrom by rememberSaveable { mutableStateOf<String?>(null) }
        val libraryItems by vm.library.collectAsState()
        val openItem = libraryItems.firstOrNull { it.id == openItemId && !it.deleted } ?: openItemCache?.takeIf { it.id == openItemId }
        fun showItem(item: LibraryItem?) {
            openItemCache = item
            openItemId = item?.id
        }
        var recheck by remember { mutableIntStateOf(0) }
        val context = LocalContext.current
        val caps = remember(helper.connected, recheck) { context.capabilities(helper.connected) }
        // The Ready check (since v1.5, Phase 1C): the chip on the Library and the Record sheet, and its sheet.
        var showReady by rememberSaveable { mutableStateOf(false) }
        var readyFix by remember { mutableStateOf<ReadyFix?>(null) }
        // Since v1.6: the plans sheet for a locked feature (Live Translate from the Record sheet).
        var upgradeLock by remember { mutableStateOf<com.echoledger.mobile.core.PlanState.Lock?>(null) }
        val armState by (application as EchoLedgerApp).graph.helper.armState.collectAsState()
        val readiness = remember(helper, armState, recheck, currentSettings.autoRecordArmed, currentSettings.paired) {
            Readiness.evaluate(ReadinessWatch.facts(context, currentSettings, (application as EchoLedgerApp).graph.helper))
        }
        LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { recheck++ }
        // Since v1.6: the home-screen widget's Ready dot follows what the app shows.
        LaunchedEffect(readiness.chip) { ReadinessWatch.remember(context, readiness) }

        // ---- app lock: decided only once the stored settings are known, then on every trip to the background ----
        val machine = rememberSaveable(saver = lockMachineSaver) { AppLockMachine(SystemClock::elapsedRealtime) }
        var lockState by remember { mutableStateOf(machine.state) }
        // Saved so a prompt still showing after a rotation is not launched a second time; Unlock always prompts.
        var unlockInFlight by rememberSaveable { mutableStateOf(false) }
        val prompter = remember { UnlockPrompter { onResult -> promptUnlock(this, onResult = onResult) } }

        fun lockInput(): LockInput = LockInput(
            enabled = currentSettings.appLockEnabled,
            available = lockAvailability(this).method != Method.None,
            lockAfterMs = currentSettings.lockAfterMs
        )

        fun publish() {
            lockState = machine.state
            // A secret copied from two-step (the key, the recovery codes) leaves the clipboard when the app locks.
            if (lockState == LockState.Locked) com.echoledger.mobile.account.SecretClip.clearNow(this@MainActivity)
            if (machine.takeNoScreenLockNotice()) vm.showMessage(NO_SCREEN_LOCK)
        }

        val onUnlockResult: (Boolean) -> Unit = { ok ->
            unlockInFlight = false
            if (ok) machine.onUnlockSucceeded() else machine.onUnlockFailed()
            publish()
        }

        fun requestUnlock() {
            unlockInFlight = true
            prompter.prompt(onUnlockResult)
        }

        LaunchedEffect(Unit) {
            when {
                !unlockInFlight -> Unit

                // Restored after process death (the lock state was not kept): that prompt can no longer answer.
                machine.state == LockState.Unknown -> unlockInFlight = false

                else -> reconnectUnlockPrompt(this@MainActivity, onUnlockResult)
            }
        }

        LaunchedEffect(loaded) {
            if (!loaded) return@LaunchedEffect
            val before = machine.state
            machine.onSettingsLoaded(lockInput())
            publish()
            val started = lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED)
            if (before == LockState.Unknown && machine.state == LockState.Locked && started && !unlockInFlight) requestUnlock()
        }
        LaunchedEffect(settings.appLockEnabled, settings.lockAfterMs) {
            machine.onSettingsChanged(lockInput())
            publish()
        }
        LifecycleEventEffect(Lifecycle.Event.ON_STOP) {
            // A rotation stops and recreates the activity; that is not leaving the app.
            if (!isChangingConfigurations) {
                machine.onStop(lockInput())
                publish()
            }
        }
        LifecycleEventEffect(Lifecycle.Event.ON_START) {
            machine.onStart(lockInput())
            publish()
            if (machine.state == LockState.Locked && !unlockInFlight) requestUnlock()
        }

        // Since v1.8 (0.3): the Phone app's copies of imported calls, only with "Delete the Phone app's copy after import"
        // on; Android itself asks the person before anything is deleted.
        val deleteCopies = rememberLauncherForActivityResult(ActivityResultContracts.StartIntentSenderForResult()) { }

        // ---- on open: sync and restart waiting uploads once the settings are known, and on every later resume ----
        var resumed by remember { mutableStateOf(false) }
        LifecycleEventEffect(Lifecycle.Event.ON_RESUME) {
            recheck++
            resumed = true
        }
        LaunchedEffect(loaded, resumed) {
            if (loaded && resumed) {
                resumed = false
                if (currentSettings.paired) {
                    vm.refresh()
                    vm.retryPending() // an upload waiting out a back-off restarts as soon as the app is opened
                }
                // Since v1.8: calls that were not recorded show in Activity as soon as the app opens.
                vm.detectMissed()
                if (android.os.Build.VERSION.SDK_INT >= 30 && lockState != LockState.Locked) {
                    // Read off the main thread: it checks each recording on the disk.
                    val copies = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                        com.echoledger.mobile.calls.PhoneAppRecordings.takeDeletes(context, currentSettings)
                    }
                    if (copies.isNotEmpty()) {
                        runCatching { android.provider.MediaStore.createDeleteRequest(contentResolver, copies).intentSender }
                            .getOrNull()
                            ?.let { deleteCopies.launch(IntentSenderRequest.Builder(it).build()) }
                    }
                }
            }
        }

        val open by openRequest
        LaunchedEffect(open) {
            // The recording notification opens the Live view (the Record tab while recording, since v1.3).
            if (open == OPEN_PENDING) {
                showItem(null)
                showSetup = false
                showWaiting = true
            }
            if (open == OPEN_LIVE) {
                tab = TAB_RECORD
                recordMode = RecordMode.VOICE
                showItem(null)
                showSetup = false
                showWaiting = false
            }
            if (open == OPEN_SETUP) {
                showItem(null)
                showSetup = true
            }
            if (open == OPEN_ACTIVITY) {
                showItem(null)
                showSetup = false
                tab = TAB_ACTIVITY
            }
            // Since v1.8 (0.3): a settings page (the Phone app's recordings waiting for the person).
            open?.takeIf { it.startsWith(OPEN_SETTINGS_PREFIX) }?.let {
                showItem(null)
                showSetup = false
                settingsPage = it.removePrefix(OPEN_SETTINGS_PREFIX)
                tab = TAB_YOU
            }
            if (open == OPEN_READY) {
                showReady = true
                readyFix = intent?.getStringExtra(EXTRA_FIX)?.let { f -> ReadyFix.entries.firstOrNull { it.name == f } }
                intent?.removeExtra(EXTRA_FIX)
            }
            open?.takeIf { it.startsWith(OPEN_SESSION_PREFIX) }?.let { openSession = it.removePrefix(OPEN_SESSION_PREFIX) }
            open?.takeIf { it.startsWith(OPEN_SHARED_PREFIX) }?.let {
                sharedNotes = it.removePrefix(OPEN_SHARED_PREFIX)
                sharedFrom = intent?.getStringExtra(EXTRA_SOURCE_APP)
                showItem(null)
                showSetup = false
            }
            // Since v1.5: a share, project or request link opens in place when it is this phone's; else in the browser.
            open?.takeIf { it.startsWith(OPEN_LINK_PREFIX) }?.removePrefix(OPEN_LINK_PREFIX)?.let { link ->
                openRequest.value = null
                val resolved = if (currentSettings.paired) vm.resolveLink(link) else null
                when (resolved?.kind) {
                    "session" -> resolved.sessionId?.let {
                        openSession = it
                        linkSession = it
                    }

                    "project", "shared", "request" -> {
                        showItem(null)
                        showSetup = false
                        tab = TAB_CLIENTS
                        vm.openInProjects(
                            when (resolved.kind) {
                                "project" -> resolved.clientId?.let { ProjectsTarget.Project(it) }
                                "shared" -> ProjectsTarget.Folder(SmartFolder.SHARED.key)
                                else -> ProjectsTarget.Requests
                            }
                        )
                    }

                    else -> openInBrowser(link)
                }
            }
            if (open != null) openRequest.value = null
        }
        // An older server answers "session" for a recording a teammate sent (it is "shared" since the v1.5 contract):
        // when it is not in the library after 8 s, Shared with me shows it instead of nothing happening.
        LaunchedEffect(linkSession) {
            val id = linkSession ?: return@LaunchedEffect
            delay(linkFallbackMs)
            if (openSession == id) {
                openSession = null
                showItem(null)
                tab = TAB_CLIENTS
                vm.openInProjects(ProjectsTarget.Folder(SmartFolder.SHARED.key))
            }
            linkSession = null
        }
        LaunchedEffect(openSession, libraryItems) {
            val id = openSession ?: return@LaunchedEffect
            libraryItems.firstOrNull { it.id == id && !it.deleted }?.let {
                tab = TAB_LIBRARY
                showSetup = false
                openAtMs = null
                showItem(it)
                openSession = null
            }
        }
        // Interface flags (contract 2.0.0-a): the server decides them; a change applies on the next screen the person
        // opens, never in the middle of a recording.
        val flagLatch = remember { UiFlagLatch() }
        val design by DesignChoice.choice.collectAsState()
        flagLatch.offer(UiFlags(settings.uiFlags, settings.uiClassic, design, settings.uiDesign))
        // A choice kept on this phone (the server could not be reached) goes to the server once it has the switch.
        LaunchedEffect(settings.uiDesign != null, design) {
            val local = design
            if (settings.uiDesign != null && local != null) {
                // The server has no new design open for this account: its answer wins.
                if (DesignChoice.choose(this@MainActivity, vm, local) == MainViewModel.DesignAnswer.NotAvailable) {
                    DesignChoice.set(this@MainActivity, null)
                }
            }
        }
        // The person's own "Try the new design" or "Switch back to classic" is a screen they open at once.
        val flags = remember(loaded, tab, openItemId, showSetup, showWaiting, sharedNotes, recheck, settings.uiClassic, design) {
            flagLatch.screenOpened(recording = RecordingService.live.value.active)
        }
        val shellOn = flags.shell
        var shellPlans by rememberSaveable { mutableStateOf(false) }
        LaunchedEffect(showWaiting, shellOn) {
            if (showWaiting && shellOn) {
                showWaiting = false
                shellActivity++
            }
        }

        LaunchedEffect(message) {
            // The v2 shell shows the messages as its own toasts.
            if (shellOn) return@LaunchedEffect
            message?.let {
                notices.show(com.echoledger.mobile.core.Notice(it, target = vm.messageTarget.value))
                vm.consumeMessage()
            }
        }
        val unlocked = loaded && lockState == LockState.Unlocked
        // The Activity badge: only what needs me, unread problems (once unlocked and paired) plus calls waiting for a decision.
        LaunchedEffect(unlocked, settings.paired) {
            if (unlocked && settings.paired) vm.activity()?.let { activityUnread = ActivityBadge.server(it.data, missed) }
        }
        // System Back closes what is open over a tab (since v1.8.1, bug P-026: Waiting for you and the Record pages
        // opened from the New sheet too), and only then leaves the app.
        BackHandler(
            enabled = unlocked && (openItem != null || showSetup || showWaiting || (recordReturnTab != null && tab == TAB_RECORD))
        ) {
            when {
                openItem != null || showSetup -> {
                    showItem(null)
                    showSetup = false
                }

                showWaiting -> showWaiting = false

                else -> {
                    tab = recordReturnTab ?: tab
                    recordMode = RecordMode.VOICE
                    recordReturnTab = null
                }
            }
        }

        // The classic tabs, the same in the classic layout and inside the v2 shell (where an area's flag is off).
        val activityTab: @Composable () -> Unit = {
            ActivityScreen(
                load = { vm.activity() },
                markRead = { vm.markActivityRead(it) },
                onOpen = { id -> openSession = id },
                onUnread = { activityUnread = it },
                waiting = pending.filter { it.state == "pending_decision" },
                onOpenWaiting = { showWaiting = true },
                scrollToTop = reselected,
                skipped = pending.filter { it.state == com.echoledger.mobile.calls.SkippedCalls.STATE },
                onRestore = { vm.restoreSkipped(it) },
                missed = missed,
                onFix = { fix ->
                    when (fix) {
                        com.echoledger.mobile.calls.MissedFix.TEST -> {
                            showItem(null)
                            showSetup = true
                        }

                        com.echoledger.mobile.calls.MissedFix.PLANS -> if (shellOn) {
                            shellPlans = true
                        } else {
                            settingsPage = "plan"
                            tab = TAB_YOU
                        }

                        // Auto-record was not running: the Ready check turns it on.
                        com.echoledger.mobile.calls.MissedFix.TURN_ON -> {
                            readyFix = ReadyFix.TURN_ON
                            showReady = true
                        }

                        else -> showReady = true
                    }
                },
                onMissedRead = { vm.readMissed(it) },
                onMissedDismiss = { vm.dismissMissed(it) }
            )
        }
        val libraryTab: @Composable () -> Unit = {
            LibraryScreen(
                vm,
                readiness = readiness,
                onReady = { showReady = true },
                onOpenAt = { item, ms ->
                    openAtMs = ms
                    showItem(item)
                },
                waiting = pending.filter { it.state == "pending_decision" },
                onOpenWaiting = { showWaiting = true },
                onSelecting = { librarySelecting = it },
                scrollToTop = reselected
            ) { showItem(it) }
        }
        val recordTab: @Composable () -> Unit = {
            RecordScreen(
                vm,
                caps,
                onSetup = { showSetup = true },
                mode = recordMode,
                onReviewWaiting = { showWaiting = true }
            )
        }
        val projectsTab: @Composable () -> Unit = {
            ProjectsScreen(vm, onOpenItem = {
                showItem(it)
            }, scrollToTop = reselected, onOpenAt = { id, ms ->
                // A call note or an answer's source: the recording opens at that moment.
                val item = libraryItems.firstOrNull { it.id == id && !it.deleted }
                if (item != null) {
                    openAtMs = ms
                    showItem(item)
                } else {
                    openSession = id
                }
            })
        }
        CompositionLocalProvider(LocalUnlockPrompter provides prompter, com.echoledger.mobile.ui.design.LocalNotices provides notices) {
            upgradeLock?.let { lock -> com.echoledger.mobile.ui.UpgradeSheet(vm, settings.plan, lock) { upgradeLock = null } }
            if (showReady) {
                ReadyCheckSheet(
                    vm,
                    readiness,
                    onDismiss = {
                        showReady = false
                        readyFix = null
                    },
                    onSetup = {
                        showReady = false
                        showItem(null)
                        showSetup = true
                    },
                    onRecheck = { recheck++ },
                    autoFix = readyFix
                )
            }
            if (recordSheet) {
                RecordSheet(caps, settings.autoRecordArmed, readyChip = readiness.chip, onReady = {
                    recordSheet = false
                    showReady = true
                }, liveTranslateLock = settings.plan.lockFor("live_translate"), onLocked = {
                    recordSheet = false
                    upgradeLock = it
                }, onPick = {
                    if (tab != TAB_RECORD) recordReturnTab = tab
                    recordMode = it
                    tab = TAB_RECORD
                    recordSheet = false
                }, onDismiss = { recordSheet = false }, liveTranslate = settings.plan.liveTranslate, onRecordNow = {
                    // Since v1.6 (tap audit): straight to recording; without the microphone allowed, the voice page asks.
                    recordSheet = false
                    if (!toggleRecording("record sheet")) {
                        if (tab != TAB_RECORD) recordReturnTab = tab
                        recordMode = RecordMode.VOICE
                        tab = TAB_RECORD
                    }
                })
            }
            Scaffold(
                containerColor = Brand.Background,
                snackbarHost = {
                    com.echoledger.mobile.ui.design.NoticeHost(notices, onOpen = { target ->
                        when (target) {
                            is com.echoledger.mobile.core.NoticeTarget.Recording -> openSession = target.sessionId

                            com.echoledger.mobile.core.NoticeTarget.Library -> {
                                showItem(null)
                                showSetup = false
                                tab = TAB_LIBRARY
                            }

                            com.echoledger.mobile.core.NoticeTarget.Ready -> showReady = true

                            is com.echoledger.mobile.core.NoticeTarget.Settings -> {
                                showItem(null)
                                tab = TAB_YOU
                            }

                            com.echoledger.mobile.core.NoticeTarget.SystemNotifications -> runCatching {
                                startActivity(
                                    Intent(android.provider.Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                                        .putExtra(android.provider.Settings.EXTRA_APP_PACKAGE, packageName)
                                )
                            }
                        }
                    })
                },
                bottomBar = {
                    val selectingNow = librarySelecting && tab == TAB_LIBRARY
                    if (unlocked && settings.onboardingDone && settings.paired && openItem == null && !showSetup && sharedNotes == null &&
                        !selectingNow && !shellOn
                    ) {
                        Column {
                            if (live.active && tab != TAB_RECORD) {
                                RecordingStrip(
                                    formatDuration(live.elapsedMs).ifEmpty { "0:00" },
                                    onOpen = {
                                        recordMode = RecordMode.VOICE
                                        tab = TAB_RECORD
                                    },
                                    onStop = { toggleRecording("recording strip") }
                                )
                            }
                            EchoBottomBar(
                                tabs = MAIN_TABS.map {
                                    if (it.key ==
                                        TAB_ACTIVITY
                                    ) {
                                        it.copy(
                                            badge = activityUnread + ActivityBadge.local(missed) +
                                                pending.count { p -> p.state == "pending_decision" }
                                        )
                                    } else {
                                        it
                                    }
                                },
                                current = tab,
                                // A tab always opens on its main screen; tapped again it closes what is open on it, then scrolls up.
                                onSelect = {
                                    tab = it
                                    recordReturnTab = null
                                    showSetup = false
                                    showWaiting = false
                                    showItem(null)
                                    recordMode = RecordMode.VOICE
                                },
                                onReselect = {
                                    showSetup = false
                                    showWaiting = false
                                    showItem(null)
                                    reselected++
                                },
                                recording = live.active,
                                onRecord = {
                                    if (!live.active) {
                                        recordSheet = true
                                    } else if (!toggleRecording("record button")) {
                                        tab =
                                            TAB_RECORD
                                    }
                                },
                                onRecordLongPress = {
                                    if (live.active || !toggleRecording("record button (hold)")) {
                                        recordMode = RecordMode.VOICE
                                        tab = TAB_RECORD
                                    }
                                }
                            )
                        }
                    }
                }
            ) { padding ->
                Box(Modifier.fillMaxSize().padding(padding)) {
                    // While the settings load or the app is locked nothing else is composed: no titles reach the
                    // accessibility tree and nothing behind the cover can be touched.
                    when {
                        !loaded || lockState == LockState.Unknown -> StartupSplash()

                        lockState == LockState.Locked -> LockScreen(onUnlock = { requestUnlock() })

                        !settings.onboardingDone -> OnboardingScreen(vm)

                        !settings.paired -> PairingScreen(vm)

                        // The new design's page for the notes when its Library is on (parity.csv PH-OTH-038).
                        sharedNotes != null && shellOn && flags.library ->
                            com.echoledger.mobile.ui.v2.shell.EchoV2Frame(vm, isDarkTheme(settings.themeMode)) {
                                com.echoledger.mobile.ui.v2.live.V2SharedNotesScreen(
                                    vm,
                                    sharedNotes.orEmpty().split(',').filter { it.isNotBlank() },
                                    onLeave = {
                                        sharedNotes = null
                                        showItem(null)
                                        tab = TAB_LIBRARY
                                    },
                                    sourceApp = sharedFrom?.let { pkg ->
                                        runCatching {
                                            packageManager.getApplicationLabel(packageManager.getApplicationInfo(pkg, 0)).toString()
                                        }.getOrNull()
                                    },
                                    onBackToSource = {
                                        sharedNotes = null
                                        tab = TAB_LIBRARY
                                        moveTaskToBack(true)
                                    }
                                )
                            }

                        sharedNotes != null -> SharedNotesScreen(
                            vm,
                            sharedNotes.orEmpty().split(',').filter {
                                it.isNotBlank()
                            },
                            // Since v1.5 (Phase 1C): the arrow and Back go to the Library, never out of the app mid-task;
                            // "Back to WhatsApp" returns to the app the note came from.
                            onLeave = {
                                sharedNotes = null
                                showItem(null)
                                tab = TAB_LIBRARY
                            },
                            sourceApp = sharedFrom?.let { pkg ->
                                runCatching {
                                    packageManager.getApplicationLabel(packageManager.getApplicationInfo(pkg, 0)).toString()
                                }.getOrNull()
                            },
                            onBackToSource = {
                                sharedNotes = null
                                tab = TAB_LIBRARY
                                moveTaskToBack(true)
                            },
                            // The new design's recording page, with ⋮ › Back to <app>, when its Library is on.
                            page = if (shellOn && flags.library) {
                                { item, back, source ->
                                    com.echoledger.mobile.ui.v2.shell.EchoV2Frame(vm, isDarkTheme(settings.themeMode)) {
                                        com.echoledger.mobile.ui.v2.library.V2RecordingScreen(
                                            vm,
                                            item,
                                            onBack = back,
                                            backToSource = source
                                        )
                                    }
                                }
                            } else {
                                { item, back, source -> SessionScreen(vm, item, onBack = back, backToSource = source) }
                            }
                        )

                        showSetup -> SetupScreen(vm, caps, onBack = { showSetup = false }, onRecheck = { recheck++ })

                        showWaiting && !shellOn -> WaitingScreen(vm, onBack = { showWaiting = false })

                        // A row opens its recording with a shared transition (since v1.3): the app tile and the title
                        // move into place; the rest fades.
                        !shellOn -> SharedTransitionLayout {
                            AnimatedContent(
                                targetState = openItem,
                                contentKey = { it?.id },
                                transitionSpec = { fadeIn(tween(Motion.SLOW)) togetherWith fadeOut(tween(Motion.FAST)) },
                                label = "recording"
                            ) { open ->
                                CompositionLocalProvider(LocalSharedScopes provides (this@SharedTransitionLayout to this@AnimatedContent)) {
                                    if (open != null) {
                                        SessionScreen(vm, open, onBack = {
                                            showItem(null)
                                            openAtMs = null
                                        }, startAtMs = openAtMs, onCaptureTest = {
                                            showItem(null)
                                            showSetup = true
                                        }, onOpenSettings = { page ->
                                            showItem(null)
                                            settingsPage = page
                                            tab = TAB_YOU
                                        })
                                    } else {
                                        AnimatedContent(
                                            targetState = tab,
                                            transitionSpec = { tabTransition(tabDirection(TAB_ORDER, initialState, targetState)) },
                                            label = "tab"
                                        ) { current ->
                                            when (current) {
                                                TAB_CLIENTS -> projectsTab()

                                                TAB_RECORD -> recordTab()

                                                TAB_ACTIVITY -> activityTab()

                                                TAB_YOU -> SettingsScreen(
                                                    vm,
                                                    onSetup = { showSetup = true },
                                                    home = reselected,
                                                    openPage = settingsPage,
                                                    onPageOpened = { settingsPage = null }
                                                )

                                                else -> libraryTab()
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        // The v2 shell (behind `ui_v2.shell`, contract 2.0.0-a): its own navigation and toasts; every area whose own
                        // flag is off shows the classic screen here.
                        // Upgrade in the new design when its Settings is on (parity.csv PH-OTH-041); an open request
                        // opens where it stands.
                        shellPlans && flags.settings -> com.echoledger.mobile.ui.v2.shell.EchoV2Frame(vm, isDarkTheme(settings.themeMode)) {
                            com.echoledger.mobile.ui.v2.plan.V2UpgradeFlow(vm, startPlan = null, feature = null, startAt = null) {
                                shellPlans = false
                            }
                        }

                        shellPlans -> {
                            BackHandler { shellPlans = false }
                            SettingsScreen(vm, onSetup = { showSetup = true }, openPage = "plan")
                        }

                        openItem != null -> SessionScreen(vm, openItem, onBack = {
                            showItem(null)
                            openAtMs = null
                        }, startAtMs = openAtMs, onCaptureTest = {
                            showItem(null)
                            showSetup = true
                        })

                        else -> EchoV2Shell(
                            vm,
                            flags,
                            darkTheme = isDarkTheme(settings.themeMode),
                            classic = ClassicScreens(
                                home = activityTab,
                                library = libraryTab,
                                live = recordTab,
                                ask = projectsTab,
                                settings = { onBack ->
                                    BackHandler(onBack = onBack)
                                    SettingsScreen(vm, onSetup = { showSetup = true })
                                },
                                upgrade = { shellPlans = true },
                                settingsPage = { page, onBack ->
                                    BackHandler(onBack = onBack)
                                    SettingsScreen(vm, onSetup = { showSetup = true }, openPage = page)
                                },
                                setup = { onBack -> SetupScreen(vm, caps, onBack = onBack, onRecheck = { recheck++ }) },
                                liveEntry = { entry, onBack ->
                                    LiveEntryScreen(
                                        entry,
                                        onBack,
                                        caps,
                                        onSetup = { showSetup = true },
                                        onReviewWaiting = { showWaiting = true }
                                    )
                                }
                            ),
                            // On this phone until the server keeps the design preference.
                            onSwitchToClassic = { classic ->
                                lifecycleScope.launch {
                                    val answer = DesignChoice.choose(this@MainActivity, vm, if (classic) Design.Classic else Design.V2)
                                    if (answer == MainViewModel.DesignAnswer.Failed) vm.designFailed()
                                }
                            },
                            help = (application as EchoLedgerApp).graph.help,
                            // The centre button: straight to recording, as classic's Record now.
                            onRecordNow = { toggleRecording("record button") },
                            onReadyCheck = { turnOn ->
                                readyFix = if (turnOn) ReadyFix.TURN_ON else null
                                showReady = true
                            },
                            openActivity = shellActivity
                        )
                    }
                }
            }
        }
    }

    /**
     * What a row of the v2 Live tab opens: the existing Record (calls), voice note and Live Translate screens, the
     * Dictation and Captions settings, and Live Assist's start on the recording under way.
     */
    @Composable
    private fun LiveEntryScreen(
        entry: com.echoledger.mobile.ui.v2.live.LiveEntry,
        onBack: () -> Unit,
        caps: com.echoledger.mobile.privilege.Capabilities,
        onSetup: () -> Unit,
        onReviewWaiting: () -> Unit
    ) {
        BackHandler(onBack = onBack)
        when (entry) {
            com.echoledger.mobile.ui.v2.live.LiveEntry.Record ->
                RecordScreen(vm, caps, onSetup = onSetup, mode = RecordMode.CALLS, onReviewWaiting = onReviewWaiting)

            // The voice note on the v2 components (Record, Pause, Stop, Discard with Undo, Import files).
            com.echoledger.mobile.ui.v2.live.LiveEntry.VoiceNote -> com.echoledger.mobile.ui.v2.live.V2VoiceNoteScreen(vm, onBack)

            com.echoledger.mobile.ui.v2.live.LiveEntry.LiveTranslate ->
                RecordScreen(vm, caps, onSetup = onSetup, mode = RecordMode.GAME, onReviewWaiting = onReviewWaiting)

            com.echoledger.mobile.ui.v2.live.LiveEntry.WatchTranslate ->
                RecordScreen(vm, caps, onSetup = onSetup, mode = RecordMode.WATCH, onReviewWaiting = onReviewWaiting)

            com.echoledger.mobile.ui.v2.live.LiveEntry.Dictation -> SettingsScreen(vm, onSetup = onSetup, openPage = "voice")

            com.echoledger.mobile.ui.v2.live.LiveEntry.Captions -> SettingsScreen(vm, onSetup = onSetup, openPage = "captions")

            com.echoledger.mobile.ui.v2.live.LiveEntry.LiveAssist -> com.echoledger.mobile.ui.v2.assist.V2AssistStart(
                onBack = onBack,
                // A recording whose captions are still starting is left alone (the record button would stop it).
                onRecord = { if (!RecordingService.isRecording) toggleRecording("live assist") },
                start = { endOther ->
                    val graph = (application as EchoLedgerApp).graph
                    graph.api()?.let { api ->
                        com.echoledger.mobile.assist.AssistHub.start(
                            api,
                            graph.settings.current().relayPins,
                            graph.scope,
                            endOther = endOther
                        )
                    } ?: com.echoledger.mobile.assist.AssistHub.Start.Failed(com.echoledger.mobile.core.Problems.SIGN_IN)
                },
                cardAllowed = { android.provider.Settings.canDrawOverlays(this) },
                showCard = {
                    com.echoledger.mobile.assist.AssistHub.active.value?.let {
                        com.echoledger.mobile.overlay.AssistCardWindow.show(this, it)
                    }
                },
                onAllowCard = {
                    runCatching {
                        startActivity(
                            Intent(android.provider.Settings.ACTION_MANAGE_OVERLAY_PERMISSION, "package:$packageName".toUri())
                        )
                    }
                }
            )
        }
    }

    /**
     * Starts or stops a recording from the record button ([from] goes to the capture log). Without the microphone
     * permission it returns false, and the caller opens the Record screen, which asks for it.
     */
    private fun toggleRecording(from: String): Boolean {
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) return false
        runCatching { startForegroundService(RecordingService.intent(this, RecordingService.ACTION_TOGGLE, from = from)) }
        return true
    }

    companion object {
        /** Intent extra naming the screen to open; see [OPEN_PENDING]. */
        const val EXTRA_OPEN = "open"
        const val OPEN_LIVE = "live"

        /** Opens the Record tab, where recordings waiting for Keep / Discard are listed. */
        const val OPEN_PENDING = "pending"

        /** Setup (call capture): from "Call capture isn't ready" and "Run capture test". */
        const val OPEN_SETUP = "setup"

        /** The Activity tab (since v1.8): from the missed-recording notice. */
        const val OPEN_ACTIVITY = "activity"

        /** Since v1.8 (0.3): "settings:<page>" opens that Settings page. */
        const val OPEN_SETTINGS_PREFIX = "settings:"

        /** The package of the app a voice note was shared from (since v1.5): "Back to WhatsApp" on its page. */
        const val EXTRA_SOURCE_APP = "source_app"

        /** The Ready check (since v1.5, Phase 1C): from the critical alert; [EXTRA_FIX] names the fix to run at once. */
        const val OPEN_READY = "ready"
        const val EXTRA_FIX = "fix"

        /** `session:<id>` opens that recording (an activity notification was tapped, B18). */
        const val OPEN_SESSION_PREFIX = "session:"

        /** `shared:<local id>,<local id>`: the voice notes just shared to EchoLedger from another app (since v1.3). */
        const val OPEN_SHARED_PREFIX = "shared:"

        /** `link:<url>`: a share, project or request link opened in the app (since v1.5). */
        const val OPEN_LINK_PREFIX = "link:"

        /** How long a link's recording may take to appear in the library before Shared with me opens instead. */
        internal var linkFallbackMs = 8_000L

        const val NO_SCREEN_LOCK = "App lock is off because this phone has no screen lock."

        /** Keeps the lock state across a rotation; a snapshot from an earlier process is ignored (cold starts re-lock). */
        private val lockMachineSaver = listSaver<AppLockMachine, Any>(
            save = { machine ->
                val saved = machine.save()
                listOf(saved.process, saved.state.name, saved.backgroundedAt ?: -1L)
            },
            restore = { values ->
                AppLockMachine(SystemClock::elapsedRealtime).apply {
                    restore(
                        AppLockMachine.Saved(
                            process = values[0] as String,
                            state = LockState.valueOf(values[1] as String),
                            backgroundedAt = (values[2] as Long).takeIf { it >= 0 }
                        )
                    )
                }
            }
        )
    }
}

private const val TAB_LIBRARY = "library"
private const val TAB_CLIENTS = "clients"
private const val TAB_RECORD = "record"
private const val TAB_ACTIVITY = "activity"
private const val TAB_YOU = "you"
private val TAB_ORDER = listOf(TAB_LIBRARY, TAB_CLIENTS, TAB_RECORD, TAB_ACTIVITY, TAB_YOU)

/** Library · Clients · [record] · Activity · You (since v1.3). */
internal val MAIN_TABS = listOf(
    NavTab(TAB_LIBRARY, "Library", Icons.Outlined.VideoLibrary, Icons.Rounded.VideoLibrary),
    NavTab(TAB_CLIENTS, "Projects", Icons.Outlined.Folder, Icons.Rounded.Folder),
    NavTab(TAB_ACTIVITY, "Activity", Icons.Outlined.Notifications, Icons.Rounded.Notifications),
    NavTab(TAB_YOU, "Settings", Icons.Outlined.Settings, Icons.Rounded.Settings)
)
