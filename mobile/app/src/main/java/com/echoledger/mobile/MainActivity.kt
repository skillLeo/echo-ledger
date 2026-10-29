package com.echoledger.mobile

import android.content.Intent
import android.os.Bundle
import android.os.SystemClock
import androidx.activity.SystemBarStyle
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.animation.AnimatedContent
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
import androidx.compose.material.icons.automirrored.outlined.LibraryBooks
import androidx.compose.material.icons.outlined.FiberManualRecord
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
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
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import androidx.lifecycle.lifecycleScope
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LibraryItem
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
import com.echoledger.mobile.ui.OnboardingScreen
import com.echoledger.mobile.ui.PairingScreen
import com.echoledger.mobile.ui.RecordScreen
import com.echoledger.mobile.ui.SessionScreen
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.SettingsState
import com.echoledger.mobile.ui.SetupScreen
import com.echoledger.mobile.ui.StartupSplash
import com.echoledger.mobile.ui.UnlockPrompter
import com.echoledger.mobile.ui.applySecureScreen
import com.echoledger.mobile.ui.capabilities
import com.echoledger.mobile.ui.isDarkTheme
import com.echoledger.mobile.ui.lockAvailability
import com.echoledger.mobile.ui.promptUnlock
import com.echoledger.mobile.ui.reconnectUnlockPrompt
import kotlinx.coroutines.launch

class MainActivity : FragmentActivity() {
    private val vm: MainViewModel by viewModels()

    /** A screen the launching intent asks for (`open = "pending"`: the Record tab with its pending list). */
    private val openRequest = mutableStateOf<String?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        if (savedInstanceState == null) openRequest.value = intent?.getStringExtra(EXTRA_OPEN)
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
            EchoLedgerTheme(themeMode, dynamicColour = settings?.dynamicColour == true) { Content() }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        intent.getStringExtra(EXTRA_OPEN)?.let { openRequest.value = it }
    }

    @Composable
    private fun Content() {
        val settingsState by vm.settingsState.collectAsState()
        val loaded = settingsState is SettingsState.Loaded
        val settings = (settingsState as? SettingsState.Loaded)?.settings ?: AppSettings()
        val currentSettings by rememberUpdatedState(settings)
        val helper by vm.helper.collectAsState()
        val message by vm.message.collectAsState()
        val snackbar = remember { SnackbarHostState() }
        // Where the user is survives a rotation (A6.M9): the tab, the open recording (by id) and the setup page.
        var tab by rememberSaveable { mutableIntStateOf(0) }
        var openItemId by rememberSaveable { mutableStateOf<String?>(null) }
        var openItemCache by remember { mutableStateOf<LibraryItem?>(null) }
        var openAtMs by rememberSaveable { mutableStateOf<Long?>(null) }
        var openSession by remember { mutableStateOf<String?>(null) }
        var showSetup by rememberSaveable { mutableStateOf(false) }
        val libraryItems by vm.library.collectAsState()
        val openItem = libraryItems.firstOrNull { it.id == openItemId && !it.deleted } ?: openItemCache?.takeIf { it.id == openItemId }
        fun showItem(item: LibraryItem?) {
            openItemCache = item
            openItemId = item?.id
        }
        var recheck by remember { mutableIntStateOf(0) }
        val context = LocalContext.current
        val caps = remember(helper.connected, recheck) { context.capabilities(helper.connected) }

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
            }
        }

        val open by openRequest
        LaunchedEffect(open) {
            // The recording notification opens the Live view (the Record tab while recording, since v1.3).
            if (open == OPEN_PENDING || open == OPEN_LIVE) {
                tab = 1
                showItem(null)
                showSetup = false
            }
            if (open == OPEN_SETUP) {
                showItem(null)
                showSetup = true
            }
            open?.takeIf { it.startsWith(OPEN_SESSION_PREFIX) }?.let { openSession = it.removePrefix(OPEN_SESSION_PREFIX) }
            if (open != null) openRequest.value = null
        }
        LaunchedEffect(openSession, libraryItems) {
            val id = openSession ?: return@LaunchedEffect
            libraryItems.firstOrNull { it.id == id && !it.deleted }?.let {
                tab = 0
                showSetup = false
                openAtMs = null
                showItem(it)
                openSession = null
            }
        }
        LaunchedEffect(message) {
            message?.let {
                snackbar.showSnackbar(it)
                vm.consumeMessage()
            }
        }
        val unlocked = loaded && lockState == LockState.Unlocked
        BackHandler(enabled = unlocked && (openItem != null || showSetup)) {
            showItem(null)
            showSetup = false
        }

        CompositionLocalProvider(LocalUnlockPrompter provides prompter) {
            Scaffold(
                containerColor = Brand.Background,
                snackbarHost = { SnackbarHost(snackbar) },
                bottomBar = {
                    if (unlocked && settings.onboardingDone && settings.paired && openItem == null && !showSetup) {
                        BottomNav(tab) { tab = it }
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

                        showSetup -> SetupScreen(vm, caps, onBack = { showSetup = false }, onRecheck = { recheck++ })

                        openItem != null -> SessionScreen(vm, openItem, onBack = {
                            showItem(null)
                            openAtMs = null
                        }, startAtMs = openAtMs, onCaptureTest = {
                            showItem(null)
                            showSetup = true
                        })

                        else -> AnimatedContent(
                            targetState = tab,
                            transitionSpec = { fadeIn() togetherWith fadeOut() },
                            label = "tab"
                        ) { current ->
                            when (current) {
                                0 -> LibraryScreen(
                                    vm,
                                    showCallSetup = caps.callCaptureSupportedByOs && !caps.helperConnected,
                                    onSetup = { showSetup = true },
                                    onOpenAt = { item, ms ->
                                        openAtMs = ms
                                        showItem(item)
                                    }
                                ) { showItem(it) }

                                1 -> RecordScreen(vm, caps, onSetup = { showSetup = true })

                                else -> SettingsScreen(vm, onSetup = { showSetup = true })
                            }
                        }
                    }
                }
            }
        }
    }

    companion object {
        /** Intent extra naming the screen to open; see [OPEN_PENDING]. */
        const val EXTRA_OPEN = "open"
        const val OPEN_LIVE = "live"

        /** Opens the Record tab, where recordings waiting for Keep / Discard are listed. */
        const val OPEN_PENDING = "pending"

        /** Setup (call capture): from "Call capture isn't ready" and "Run capture test". */
        const val OPEN_SETUP = "setup"

        /** `session:<id>` opens that recording (an activity notification was tapped, B18). */
        const val OPEN_SESSION_PREFIX = "session:"

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

@Composable
private fun BottomNav(selected: Int, onSelect: (Int) -> Unit) {
    val items = listOf(
        Triple("Library", Icons.AutoMirrored.Outlined.LibraryBooks, 0),
        Triple("Record", Icons.Outlined.FiberManualRecord, 1),
        Triple("Settings", Icons.Outlined.Settings, 2)
    )
    Surface(color = Brand.Surface, tonalElevation = 0.dp) {
        Row(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 12.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            items.forEach { (label, icon, index) ->
                val active = selected == index
                Column(
                    Modifier.weight(1f)
                        .selectable(selected = active, onClick = { onSelect(index) })
                        .background(
                            if (active) Brand.Violet.copy(alpha = 0.14f) else androidx.compose.ui.graphics.Color.Transparent,
                            RoundedCornerShape(14.dp)
                        )
                        .padding(vertical = 8.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(3.dp)
                ) {
                    Icon(
                        icon,
                        contentDescription = label,
                        tint = if (active) Brand.Violet else Brand.Faint,
                        modifier = Modifier.size(22.dp)
                    )
                    Text(
                        label,
                        color = if (active) Brand.Text else Brand.Faint,
                        fontSize = 11.sp,
                        fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal
                    )
                }
            }
        }
    }
}
