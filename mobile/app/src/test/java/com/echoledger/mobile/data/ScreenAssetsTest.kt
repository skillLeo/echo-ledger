package com.echoledger.mobile.data

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** A screen's name in the meeting pack and what was said around it (contract 2.0.0-e): read when there, null when not. */
class ScreenAssetsTest {
    private fun screen(fields: String) = ApiJson.decodeFromString(ScreenDto.serializer(), """{"id":"s1"$fields}""")

    private fun projectScreen(fields: String) =
        ApiJson.decodeFromString(ProjectScreenDto.serializer(), """{"id":"s1","session_id":"r1"$fields}""")

    @Test
    fun `the server's sample gives the file name and the lines said`() {
        val sample = File("../../docs/api-samples/session_screens.json").readText()
        val first = ApiJson.decodeFromString(ScreensResponse.serializer(), sample).data.first()
        assertEquals("S01_0-05_a-slide-with-a-price.webp", first.fileName)
        assertEquals("Me: Here is the price.", first.said)
    }

    @Test
    fun `both kinds of screen read them`() {
        val fields = ""","file_name":"S07_14-32_invoice-page.webp","said":"Client: Is that the total?\nMe: Yes.""""
        assertEquals("S07_14-32_invoice-page.webp", screen(fields).fileName)
        assertEquals("Client: Is that the total?\nMe: Yes.", projectScreen(fields).said)
        assertEquals("S07_14-32_invoice-page.webp", projectScreen(fields).fileName)
    }

    @Test
    fun `missing, null or of another type is null, never an error`() {
        for (fields in listOf(
            "",
            ""","file_name":null,"said":null""",
            ""","file_name":7,"said":{"x":1}""",
            ""","file_name":[],"said":true"""
        )) {
            assertNull(fields, screen(fields).fileName)
            assertNull(fields, screen(fields).said)
            assertNull(fields, projectScreen(fields).fileName)
            assertNull(fields, projectScreen(fields).said)
        }
    }
}
