package com.echoledger.mobile.net

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class UploadPolicyTest {
    @Test
    fun `validation and other client errors are final, not retried forever`() {
        listOf(400, 401, 403, 404, 413, 422).forEach { assertTrue("$it", UploadWorker.isPermanent(it)) }
    }

    @Test
    fun `rate limits, timeouts and server errors are worth retrying`() {
        listOf(408, 429, 500, 502, 503, 504).forEach { assertFalse("$it", UploadWorker.isPermanent(it)) }
    }

    @Test
    fun `a failed upload says what happened and what to do, never a raw exception`() {
        org.junit.Assert.assertEquals(
            UploadWorker.NO_CONNECTION,
            UploadWorker.friendly(java.net.UnknownHostException("Unable to resolve host \"ledger.example.com\""))
        )
        org.junit.Assert.assertEquals(UploadWorker.NO_CONNECTION, UploadWorker.friendly(java.net.SocketTimeoutException("timeout")))
        assertTrue(UploadWorker.friendly(javax.net.ssl.SSLHandshakeException("bad cert")).contains("This phone"))
        assertTrue(UploadWorker.friendly(java.io.IOException("stream was reset")).endsWith("tap Retry to try now."))
        assertTrue(UploadWorker.friendly(IllegalStateException()).contains("Tap Retry"))
        assertFalse(UploadWorker.friendly(java.net.UnknownHostException("Unable to resolve host")).contains("resolve"))
    }
}
