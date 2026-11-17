package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.ScreenTest
import org.junit.Test

/**
 * Settings show people only what they can use (since v1.8.1, bugs P-018 and P-019): no Audio probe with package names
 * and player states outside the builds for the people who make the app, no Capture test in a build without call
 * capture, and no testing switch on the Games page. Copy diagnostics stays everywhere.
 */
class EngineeringOnlyTest : ScreenTest() {
    @Test
    fun `Help shows the capture test and Copy diagnostics, never the Audio probe`() {
        show { HelpSection(vm, AppSettings(), onSetup = {}, captureInBuild = true, engineering = false) }
        compose.onNodeWithText("Capture test").assertExists()
        compose.onNodeWithText("Copy diagnostics").assertExists()
        compose.onAllNodesWithText("Audio probe").assertCountEquals(0)
    }

    @Test
    fun `a build without call capture offers no capture test`() {
        show { HelpSection(vm, AppSettings(), onSetup = {}, captureInBuild = false, engineering = false) }
        compose.onAllNodesWithText("Capture test").assertCountEquals(0)
        compose.onNodeWithText("Copy diagnostics").assertExists()
    }

    @Test
    fun `an engineering build still has the Audio probe`() {
        show { HelpSection(vm, AppSettings(), onSetup = {}, captureInBuild = true, engineering = true) }
        compose.onNodeWithText("Audio probe").assertExists()
    }

    @Test
    fun `the Games page has no testing switch`() {
        show { GameMoreSection(vm, AppSettings()) }
        compose.onAllNodesWithText("Show speed details").assertCountEquals(0)
        compose.onAllNodesWithText("for testing", substring = true).assertCountEquals(0)
    }
}
