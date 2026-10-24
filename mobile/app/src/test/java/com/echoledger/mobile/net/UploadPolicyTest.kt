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
}
