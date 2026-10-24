package com.echoledger.mobile

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.LibraryBooks
import androidx.compose.material.icons.outlined.FiberManualRecord
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.Brand
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.LibraryScreen
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.PairingScreen
import com.echoledger.mobile.ui.RecordScreen
import com.echoledger.mobile.ui.SessionScreen
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.SetupScreen
import com.echoledger.mobile.ui.capabilities

class MainActivity : ComponentActivity() {
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
                        if (settings.paired && openItem == null && !showSetup) {
                            NavigationBar(containerColor = Brand.Surface) {
                                NavigationBarItem(selected = tab == 0, onClick = {
                                    tab = 0
                                }, icon = { Icon(Icons.AutoMirrored.Outlined.LibraryBooks, null) }, label = { Text("Library") })
                                NavigationBarItem(selected = tab == 1, onClick = {
                                    tab = 1
                                }, icon = { Icon(Icons.Outlined.FiberManualRecord, null) }, label = { Text("Record") })
                                NavigationBarItem(selected = tab == 2, onClick = {
                                    tab = 2
                                }, icon = { Icon(Icons.Outlined.Settings, null) }, label = { Text("Settings") })
                            }
                        }
                    }
                ) { padding ->
                    Box(Modifier.fillMaxSize().padding(padding)) {
                        when {
                            !settings.paired -> PairingScreen(vm)
                            showSetup -> SetupScreen(vm, caps, onBack = { showSetup = false }, onRecheck = { recheck++ })
                            openItem != null -> SessionScreen(vm, openItem!!, onBack = { openItem = null })
                            tab == 0 -> LibraryScreen(vm) { openItem = it }
                            tab == 1 -> RecordScreen(vm, caps, onSetup = { showSetup = true })
                            else -> SettingsScreen(vm, onSetup = { showSetup = true })
                        }
                    }
                }
            }
        }
    }
}
