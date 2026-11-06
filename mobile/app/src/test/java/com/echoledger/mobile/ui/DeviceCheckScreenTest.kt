package com.echoledger.mobile.ui

import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** Help & diagnostics → Device check (since v1.5): nothing leaves the phone until Send to my admin is tapped. */
class DeviceCheckScreenTest : ScreenTest() {
    private fun show() {
        show {
            val settings by vm.settings.collectAsState()
            DeviceCheckSection(vm, settings, onSetup = {})
        }
    }

    @Test
    fun `it lists what works, and sends the report only when asked`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/devices/report", FakeServer.json(201, """{"data":{"reported_at":"2026-11-06T10:00:00+00:00"}}"""))
        show()

        compose.onNodeWithText("App calls").assertExists()
        compose.onNodeWithText("Voice notes and import").assertExists()
        compose.onNodeWithText("Staying ready").assertExists()
        assertTrue("nothing is sent by itself", server.requestsTo("POST", "/api/devices/report").isEmpty())

        compose.onNodeWithText("Send to my admin").performClick()
        pumpUntil("the report to reach the server") { server.requestsTo("POST", "/api/devices/report").isNotEmpty() }

        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/devices/report").single().bodyText()).jsonObject
        assertEquals("android", body["platform"]!!.jsonPrimitive.content)
        val keys = body["checks"]!!.jsonArray.map { it.jsonObject["key"]!!.jsonPrimitive.content }
        assertEquals(listOf("voice_notes", "app_calls", "sim_calls", "captions", "live_translate", "background", "memory"), keys)
    }

    @Test
    fun `signed out, the report can be copied but not sent`() {
        show()

        compose.onNodeWithText("Send to my admin").assertIsNotEnabled()
        compose.onNodeWithText("Sign in to send the report to your admin", substring = true).assertExists()
        compose.onNodeWithText("Copy report").assertExists()
    }
}
