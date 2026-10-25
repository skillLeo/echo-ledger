package com.echoledger.mobile.ui

import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.SecurityDeviceDto
import com.echoledger.mobile.data.SecurityEventDto
import com.echoledger.mobile.data.SecurityResponse
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The security centre on the phone (B16): paired devices, revoking another one, and the recent audit log. */
class SecurityCentreTest : ScreenTest() {
    private val overview = SecurityResponse(
        devices = listOf(SecurityDeviceDto("d1", "Pixel 9", "android", current = true), SecurityDeviceDto("d2", "Work laptop", "windows")),
        events = listOf(
            SecurityEventDto(
                7,
                "session_deleted",
                "Recording deleted",
                "Recording deleted: Acme kickoff",
                "device",
                "Work laptop",
                "2026-10-28T09:00:00Z"
            )
        )
    )

    @Test
    fun `each event says who did it`() {
        assertEquals("Signed in · Console", eventLine(SecurityEventDto(1, "login", "Signed in", "Signed in", "web")))
        assertEquals(
            "Device revoked · A device",
            eventLine(SecurityEventDto(2, "device_revoked", "Device revoked", "Device revoked", "device"))
        )
        assertEquals("Recording deleted · EchoLedger", eventLine(SecurityEventDto(3, "session_deleted", "Recording deleted", "", "system")))
    }

    @Test
    fun `the overview comes from the server and a device can be revoked`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/security",
            FakeServer.json(
                200,
                """{"devices":[{"id":"d1","name":"Pixel 9","platform":"android","current":true}],"events":[{"id":1,"event":"device_paired","label":"Device paired","summary":"Device paired: Pixel 9","actor":"device","device":"Pixel 9","at":"2026-10-28T09:00:00+00:00"}]}"""
            )
        )
        server.on("DELETE", "/api/devices/d2", FakeServer.empty(204))
        server.on(
            "DELETE",
            "/api/devices/d1",
            FakeServer.json(
                409,
                """{"code":"current_device","message":"This is the device you are using. Unpair it in its settings instead."}"""
            )
        )

        val answer = runBlocking { vm.security() }!!
        assertTrue(answer.devices.single().current)
        assertEquals("device_paired", answer.events.single().event)
        assertTrue(runBlocking { vm.revokeDevice("d2") })
        assertFalse(runBlocking { vm.revokeDevice("d1") })
        assertEquals("This is the device you are using. Unpair it in its settings instead.", vm.message.value)
    }

    @Test
    fun `offline there is no overview`() {
        assertNull(runBlocking { vm.security() })
    }

    @Test
    fun `the section lists devices, asks once more before revoking and shows activity`() {
        val revoked = mutableListOf<String>()
        var loads = 0
        show {
            SecuritySection(load = {
                loads++
                overview
            }, revoke = {
                revoked += it
                true
            })
        }
        pumpUntil("the devices to show") { runCatching { compose.onNodeWithText("Pixel 9 · this phone").assertExists() }.isSuccess }
        compose.onNodeWithText("Recording deleted: Acme kickoff · Work laptop").assertExists()
        compose.onNodeWithContentDescription("Revoke Work laptop").performClick()
        assertTrue(revoked.isEmpty())
        compose.onNodeWithText("Revoke now").performClick()
        pumpUntil("the device to be revoked") { revoked == listOf("d2") && loads == 2 }
    }
}
