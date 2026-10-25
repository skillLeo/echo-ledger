package com.echoledger.mobile.data

import app.cash.turbine.test
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.NotPairedException
import com.echoledger.mobile.testsupport.AesGcmSecretCipher
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import java.io.File
import java.util.concurrent.atomic.AtomicInteger
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

/** Unified library sync: paging, last-write-wins merge, tombstones, the encrypted offline cache and error safety. */
class LibraryRepositoryTest : AppTest() {
    private val cacheFile get() = File(app.noBackupFilesDir, "library.bin")

    private fun repository(cipher: SecretCipher = app.graph.secretCipher, paired: Boolean = true) =
        LibraryRepository(app, cipher) { if (paired) ApiClient(server.baseUrl, emptyList(), "7|eld_token") else null }

    private fun item(
        id: String,
        started: String = "2026-10-20T10:00:00+00:00",
        updated: String = "2026-10-20T10:05:00+00:00",
        title: String? = "Call $id"
    ) = ServerFixtures.libraryItem(id, title = title, startedAt = started, updatedAt = updated)

    private fun page(vararg items: String, next: String? = null, time: String = "2026-10-24T09:05:00+00:00") =
        FakeServer.json(200, ServerFixtures.libraryPage(items.toList(), next, time))

    private fun libraryRequests() = server.requestsTo("GET", "/api/library")

    // ---- first sync, incremental sync ---------------------------------------------------------------------------------------

    @Test
    fun `the first sync loads every item newest first and remembers the server time`() = runBlocking {
        server.on(
            "GET",
            "/api/library",
            page(
                item("a", started = "2026-10-01T09:00:00+00:00"),
                item("b", started = "2026-10-03T09:00:00+00:00"),
                item("c", started = "2026-10-02T09:00:00+00:00")
            )
        )
        val repo = repository()

        val changed = repo.refresh()

        assertEquals(3, changed)
        assertEquals(listOf("b", "c", "a"), repo.items.value.map { it.id })
        assertNull("the first sync must ask for everything", libraryRequests().single().url.queryParameter("since"))
    }

    @Test
    fun `the next sync asks only for changes since the previous server time and merges them`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library", page(item("a", title = "Old title"), item("b"), time = "2026-10-24T09:05:00+00:00"))
        repo.refresh()

        server.on(
            "GET",
            "/api/library",
            page(
                item("a", title = "New title", updated = "2026-10-24T09:00:00+00:00"),
                ServerFixtures.tombstone("b", "2026-10-24T09:02:00+00:00"),
                item("c"),
                time = "2026-10-24T09:10:00+00:00"
            )
        )
        val changed = repo.refresh()

        assertEquals(3, changed)
        assertEquals("2026-10-24T09:05:00+00:00", libraryRequests().last().url.queryParameter("since"))
        assertEquals(setOf("a", "c"), repo.items.value.map { it.id }.toSet())
        assertEquals("New title", repo.items.value.single { it.id == "a" }.title)
    }

    @Test
    fun `an incremental sync with no changes keeps the library as it was`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library", page(item("a"), item("b")))
        repo.refresh()
        server.on("GET", "/api/library", page(time = "2026-10-24T09:20:00+00:00"))

        assertEquals(0, repo.refresh())

        assertEquals(2, repo.items.value.size)
        assertEquals("2026-10-24T09:05:00+00:00", libraryRequests().last().url.queryParameter("since"))
        repo.refresh()
        assertEquals(
            "the time of the empty sync becomes the new cursor",
            "2026-10-24T09:20:00+00:00",
            libraryRequests().last().url.queryParameter("since")
        )
    }

    @Test
    fun `paging follows the cursor keeps one since and stores the first page time`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library") { request ->
            when (request.url.queryParameter("cursor")) {
                null -> FakeServer.json(200, ServerFixtures.libraryPage(listOf(item("a"), item("b")), "c1", "2026-10-24T09:05:00+00:00"))

                "c1" -> FakeServer.json(
                    200,
                    ServerFixtures.libraryPage(listOf(item("c"), ServerFixtures.tombstone("a")), "c2", "2026-10-24T09:06:00+00:00")
                )

                else -> FakeServer.json(200, ServerFixtures.libraryPage(listOf(item("d")), null, "2026-10-24T09:07:00+00:00"))
            }
        }

        val changed = repo.refresh()

        assertEquals(5, changed)
        assertEquals(setOf("b", "c", "d"), repo.items.value.map { it.id }.toSet())
        assertEquals(listOf(null, "c1", "c2"), libraryRequests().map { it.url.queryParameter("cursor") })
        server.on("GET", "/api/library", page())
        repo.refresh()
        assertEquals("2026-10-24T09:05:00+00:00", libraryRequests().last().url.queryParameter("since"))
    }

    @Test
    fun `a failure on a later page changes nothing and the next sync starts over from the same point`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library", page(item("a"), time = "2026-10-24T09:05:00+00:00"))
        repo.refresh()
        server.on("GET", "/api/library") { request ->
            if (request.url.queryParameter("cursor") == null) {
                FakeServer.json(
                    200,
                    ServerFixtures.libraryPage(listOf(item("b"), ServerFixtures.tombstone("a")), "c1", "2026-10-24T09:10:00+00:00")
                )
            } else {
                FakeServer.json(500, """{"message":"Server Error"}""")
            }
        }

        val error = runCatching { repo.refresh() }.exceptionOrNull()

        assertTrue(error is ApiException && error.status == 500)
        assertEquals("no partial result may be committed", listOf("a"), repo.items.value.map { it.id })
        server.on("GET", "/api/library", page(item("b"), ServerFixtures.tombstone("a"), time = "2026-10-24T09:12:00+00:00"))
        repo.refresh()
        assertEquals("2026-10-24T09:05:00+00:00", libraryRequests().last().url.queryParameter("since"))
        assertEquals(listOf("b"), repo.items.value.map { it.id })
    }

    @Test
    fun `a revoked device surfaces as NotPaired and leaves the cached library visible`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library", page(item("a")))
        repo.refresh()
        server.on("GET", "/api/library", FakeServer.json(401, """{"message":"Unauthenticated."}"""))

        assertThrows(NotPairedException::class.java) { runBlocking { repo.refresh() } }

        assertEquals(listOf("a"), repo.items.value.map { it.id })
    }

    @Test
    fun `an unpaired phone refreshes nothing and sends no request`() = runBlocking {
        val repo = repository(paired = false)

        assertEquals(0, repo.refresh())

        assertTrue(repo.items.value.isEmpty())
        assertTrue(server.requests.isEmpty())
    }

    @Test
    fun `items arrive on the state flow as the sync completes`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library", page(item("a")))

        repo.items.test {
            assertTrue(awaitItem().isEmpty())
            repo.refresh()
            assertEquals(listOf("a"), awaitItem().map { it.id })
            cancelAndIgnoreRemainingEvents()
        }
    }

    // ---- offline cache --------------------------------------------------------------------------------------------------

    @Test
    fun `the cache is encrypted and a new instance restores items and the sync point from it`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library", page(item("a", title = "Confidential merger call"), time = "2026-10-24T09:05:00+00:00"))
        repo.refresh()

        val raw = cacheFile.readBytes()
        assertFalse("titles must not be readable at rest", String(raw, Charsets.ISO_8859_1).contains("Confidential"))
        assertFalse(File(app.noBackupFilesDir, "library.bin.tmp").exists())

        val reopened = repository()
        assertEquals(listOf("a"), reopened.items.value.map { it.id })
        assertEquals("Confidential merger call", reopened.items.value.single().title)
        server.on("GET", "/api/library", page(time = "2026-10-24T09:30:00+00:00"))
        reopened.refresh()
        assertEquals("2026-10-24T09:05:00+00:00", libraryRequests().last().url.queryParameter("since"))
    }

    @Test
    fun `the cache keeps up with later syncs`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library", page(item("a")))
        repo.refresh()
        server.on("GET", "/api/library", page(item("b"), ServerFixtures.tombstone("a"), time = "2026-10-24T09:30:00+00:00"))
        repo.refresh()

        assertEquals(listOf("b"), repository().items.value.map { it.id })
    }

    @Test
    fun `an unreadable cache starts an empty library instead of crashing`() = runBlocking {
        listOf(ByteArray(0), ByteArray(5) { 1 }, ByteArray(500) { it.toByte() }).forEach { garbage ->
            cacheFile.writeBytes(garbage)
            val repo = repository()
            assertTrue(repo.items.value.isEmpty())
        }
    }

    @Test
    fun `a cache sealed with another key is ignored`() = runBlocking {
        val first = repository(cipher = AesGcmSecretCipher())
        server.on("GET", "/api/library", page(item("a")))
        first.refresh()

        assertTrue(repository(cipher = AesGcmSecretCipher()).items.value.isEmpty())
    }

    @Test
    fun `a corrupt cache is replaced by the next successful sync`() = runBlocking {
        cacheFile.writeBytes(ByteArray(100) { 7 })
        val repo = repository()
        server.on("GET", "/api/library", page(item("a")))

        repo.refresh()

        assertNull("an unreadable cache must not suppress the first full sync", libraryRequests().single().url.queryParameter("since"))
        assertEquals(listOf("a"), repository().items.value.map { it.id })
    }

    @Test
    fun `clear forgets items the sync point and the cache file`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library", page(item("a")))
        repo.refresh()

        repo.clear()

        assertTrue(repo.items.value.isEmpty())
        assertFalse(cacheFile.exists())
        repo.refresh()
        assertNull("after unpairing the next sync must be a full one", libraryRequests().last().url.queryParameter("since"))
    }

    @Test
    fun `unicode titles previews and client names survive the cache`() = runBlocking {
        val repo = repository()
        server.on(
            "GET",
            "/api/library",
            page(ServerFixtures.libraryItem("u", title = "Café 你好 😀", preview = "مرحبا", client = "Müller & Söhne"))
        )
        repo.refresh()

        val restored = repository().items.value.single()
        assertEquals("Café 你好 😀", restored.title)
        assertEquals("مرحبا", restored.transcript!!.preview)
        assertEquals("Müller & Söhne", restored.client!!.name)
    }

    // ---- concurrency and scale ---------------------------------------------------------------------------------------------

    @Test
    fun `overlapping refreshes run one at a time`() = runBlocking {
        val repo = repository()
        val inFlight = AtomicInteger()
        val maxInFlight = AtomicInteger()
        server.on("GET", "/api/library") {
            val now = inFlight.incrementAndGet()
            maxInFlight.updateAndGet { maxOf(it, now) }
            Thread.sleep(150)
            inFlight.decrementAndGet()
            FakeServer.json(200, ServerFixtures.libraryPage(listOf(item("a")), null))
        }

        (1..4).map { async { repo.refresh() } }.awaitAll()

        assertEquals(1, maxInFlight.get())
        assertEquals(listOf("a"), repo.items.value.map { it.id })
    }

    @Test
    fun `a library of several thousand items syncs and sorts promptly`() = runBlocking {
        val repo = repository()
        val items = (0 until 3_000).map { n ->
            item("id$n", started = "2026-%02d-%02dT00:00:00+00:00".format(1 + n % 12, 1 + n % 28), title = "Call $n")
        }
        server.on("GET", "/api/library", page(*items.toTypedArray()))
        val started = System.nanoTime()

        repo.refresh()

        assertEquals(3_000, repo.items.value.size)
        val ordered = repo.items.value.map { it.startedAt.orEmpty() }
        assertEquals(ordered.sortedDescending(), ordered)
        assertTrue("took ${(System.nanoTime() - started) / 1_000_000} ms", (System.nanoTime() - started) < 20_000_000_000L)
    }

    @Test
    fun `a body that is not JSON surfaces an error without touching the library`() = runBlocking {
        val repo = repository()
        server.on("GET", "/api/library", page(item("a")))
        repo.refresh()
        server.on("GET", "/api/library", MockResponse.Builder().code(200).body("<html>captive portal</html>").build())

        assertTrue(runCatching { repo.refresh() }.isFailure)
        assertEquals(listOf("a"), repo.items.value.map { it.id })
    }
}
