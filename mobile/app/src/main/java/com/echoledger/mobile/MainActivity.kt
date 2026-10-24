package com.echoledger.mobile

import android.os.Bundle
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
import androidx.compose.foundation.layout.height
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
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.Brand
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.LibraryScreen
import com.echoledger.mobile.ui.LockScreen
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.PairingScreen
import com.echoledger.mobile.ui.RecordScreen
import com.echoledger.mobile.ui.SessionScreen
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.SetupScreen
import com.echoledger.mobile.ui.biometricAvailable
import com.echoledger.mobile.ui.capabilities
import com.echoledger.mobile.ui.promptUnlock

class MainActivity : FragmentActivity() {
    private val vm: MainViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            EchoLedgerTheme {
                val settings by vm.settings.collectAsState()
                val helper by vm.helper.collectAsState()
                val message by vm.message.collectAsState()
                val snackbar = remember { SnackbarHostState() }
                var tab by remember { mutableIntStateOf(0) }
                var openItem by remember { mutableStateOf<LibraryItem?>(null) }
                var showSetup by remember { mutableStateOf(false) }
                var recheck by remember { mutableIntStateOf(0) }
                val context = LocalContext.current
                val caps = remember(helper.connected, recheck) { context.capabilities(helper.connected) }

                // Biometric app lock: cover the app until unlocked when the setting is on and the phone supports it.
                val lockEligible = settings.appLockEnabled && biometricAvailable(this)
                var locked by remember { mutableStateOf(lockEligible) }
                LaunchedEffect(lockEligible) { if (!lockEligible) locked = false }
                LaunchedEffect(locked) { if (locked) promptUnlock(this@MainActivity) { ok -> if (ok) locked = false } }

                LifecycleEventEffect(Lifecycle.Event.ON_RESUME) {
                    recheck++
                    if (settings.paired) vm.refresh()
                }
                LaunchedEffect(message) {
                    message?.let {
                        snackbar.showSnackbar(it)
                        vm.consumeMessage()
                    }
                }
                BackHandler(enabled = openItem != null || showSetup) {
                    openItem = null
                    showSetup = false
                }

                Scaffold(
                    containerColor = Brand.Background,
                    snackbarHost = { SnackbarHost(snackbar) },
                    bottomBar = {
                        if (settings.paired && openItem == null && !showSetup && !locked) {
                            BottomNav(tab) { tab = it }
                        }
                    }
                ) { padding ->
                    Box(Modifier.fillMaxSize().padding(padding)) {
                        when {
                            !settings.paired -> PairingScreen(vm)

                            showSetup -> SetupScreen(vm, caps, onBack = { showSetup = false }, onRecheck = { recheck++ })

                            openItem != null -> SessionScreen(vm, openItem!!, onBack = { openItem = null })

                            else -> AnimatedContent(
                                targetState = tab,
                                transitionSpec = { fadeIn() togetherWith fadeOut() },
                                label = "tab"
                            ) { current ->
                                when (current) {
                                    0 -> LibraryScreen(vm) { openItem = it }
                                    1 -> RecordScreen(vm, caps, onSetup = { showSetup = true })
                                    else -> SettingsScreen(vm, onSetup = { showSetup = true })
                                }
                            }
                        }
                        if (locked) LockScreen(onUnlock = { promptUnlock(this@MainActivity) { ok -> if (ok) locked = false } })
                    }
                }
            }
        }
    }
}

@androidx.compose.runtime.Composable
private fun BottomNav(selected: Int, onSelect: (Int) -> Unit) {
    val items = listOf(
        Triple("Library", Icons.AutoMirrored.Outlined.LibraryBooks as ImageVector, 0),
        Triple("Record", Icons.Outlined.FiberManualRecord as ImageVector, 1),
        Triple("Settings", Icons.Outlined.Settings as ImageVector, 2)
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
