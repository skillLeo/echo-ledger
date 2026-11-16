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
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoBottomBar
import com.echoledger.mobile.ui.v2.components.EchoDestination
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.EchoToastState
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.library.V2TrashScreen
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.theme.systemEchoLanguage
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
    data class Recording(val id: String) : V2Route
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
    val upgrade: () -> Unit
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
    language: EchoLanguage = systemEchoLanguage()
) {
    EchoV2Theme(darkTheme = darkTheme, language = language) {
        val toasts = rememberEchoToastState()
        CompositionLocalProvider(LocalV2Text provides V2Text.of(language), LocalEchoToasts provides toasts) {
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
    LaunchedEffect(message) {
        val text = message ?: return@LaunchedEffect
        vm.consumeMessage()
        scope.launch { toasts.show(text) }
    }
}

@Composable
private fun ShellBody(vm: MainViewModel, flags: UiFlags, classic: ClassicScreens, onSwitchToClassic: (Boolean) -> Unit) {
    val text = v2Text
    var tab by rememberSaveable { mutableStateOf(V2Tab.Library) }
    var route by remember { mutableStateOf<V2Route>(V2Route.Tabs) }
    val library by vm.library.collectAsState()
    val settings by vm.settings.collectAsState()
    BackHandler(enabled = route != V2Route.Tabs) {
        route = when (route) {
            V2Route.ClassicSettings -> V2Route.Settings
            else -> V2Route.Tabs
        }
    }
    val accountName by produceState<String?>(null) { value = vm.accountQuietly()?.name }
    val initials = initialsOf(accountName ?: settings.plan.workspace)
    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        Box(Modifier.weight(1f).fillMaxWidth()) {
            when (val r = route) {
                V2Route.Settings -> V2SettingsHome(
                    vm = vm,
                    flags = flags,
                    onSwitchToClassic = onSwitchToClassic,
                    onUpgrade = classic.upgrade,
                    onAllSettings = { route = V2Route.ClassicSettings },
                    onBack = { route = V2Route.Tabs }
                )

                V2Route.ClassicSettings -> classic.settings { route = V2Route.Settings }

                V2Route.Trash -> V2TrashScreen(vm, retentionDays = 30, onBack = { route = V2Route.Tabs })

                is V2Route.Recording -> {
                    val item = library.firstOrNull { it.id == r.id }
                    if (item == null) {
                        LaunchedEffect(r.id) { route = V2Route.Tabs }
                    } else {
                        V2RecordingScreen(vm, item, onBack = { route = V2Route.Tabs })
                    }
                }

                V2Route.Tabs -> when (tab) {
                    V2Tab.Home -> classic.home()

                    V2Tab.Library -> if (flags.library) {
                        V2LibraryScreen(
                            vm = vm,
                            initials = initials,
                            onOpen = { route = V2Route.Recording(it.id) },
                            onTrash = { route = V2Route.Trash },
                            onAccount = { route = V2Route.Settings }
                        )
                    } else {
                        classic.library()
                    }

                    V2Tab.Live -> classic.live()

                    V2Tab.Ask -> classic.ask()
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
