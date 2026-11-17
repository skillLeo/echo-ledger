package com.echoledger.mobile.ui.v2

import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.MainViewModel
import kotlinx.coroutines.runBlocking

/** One project (Qureshi Textiles, with its folder March order) as the server sends it: the workspace, rules, screens, Ask. */
object WorkspaceFixtures {
    const val PROJECT = "01JCLIENT0000000000000000A"
    const val FOLDER = "01JCLIENT0000000000000000B"

    fun serve(server: FakeServer, vm: MainViewModel, app: EchoLedgerApp) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/clients",
            FakeServer.json(
                200,
                """{"data":[{"id":"$PROJECT","name":"Qureshi Textiles","session_count":1},
                {"id":"$FOLDER","name":"March order","parent_id":"$PROJECT","session_count":1}]}"""
            )
        )
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        ServerFixtures.libraryItem("s1", title = "Payment terms for the March shipment", client = "Qureshi Textiles"),
                        ServerFixtures.libraryItem("s2", title = "Cutting schedule", client = "March order").replace(PROJECT, FOLDER)
                    )
                )
            )
        )
        server.on(
            "GET",
            "/api/clients/$PROJECT/workspace",
            FakeServer.json(
                200,
                """{"data":{"client":{"id":"$PROJECT","name":"Qureshi Textiles","ai_notes":true},
                "ai":{"configured":true,"spent_usd":1.2,"budget_usd":5,"client_spent_usd":0.4},
                "brief":{"sections":{"goal":"Ship 40,000 metres of cotton lawn in March.","scope":[],"constraints":[],"deadlines":["Packing done by 21 March"],"budget":[],"people":[],"glossary":[]},"edited":false,"status":"ready"},
                "memory":[{"id":"m1","kind":"decision","text":"40% in advance, the balance against the bill of lading","pinned":false,"source":{"type":"call","id":"s1","at_ms":31000}},
                {"id":"m2","kind":"question","text":"Which bank opens the LC?","pinned":true,"source":{"type":"user"}}],
                "notes":[{"id":"n1","session_id":"s1","status":"ready","language":"en","sections":[{"key":"decisions","heading":"Decisions","items":[{"text":"40% advance","verify":false,"at_ms":31000}]}],"session":{"id":"s1","title":"Payment terms for the March shipment","started_at":"2026-11-17T06:42:00Z"}}],
                "files":[{"id":"f1","name":"Proforma March.pdf","mime":"application/pdf","size_bytes":120000,"pages":2,"status":"ready","instructions":false},
                {"id":"f2","name":"House rules","mime":"text/plain","size_bytes":900,"status":"ready","instructions":true}]}}"""
            )
        )
        server.on("GET", "/api/clients/$PROJECT/rules", FakeServer.json(200, """{"data":[{"id":7,"kind":"title","value":"Qureshi"}]}"""))
        server.on(
            "GET",
            "/api/clients/$PROJECT/screens",
            FakeServer.json(
                200,
                """{"data":[{"id":"sc1","index":7,"at_ms":872000,"width":1920,"height":1080,"trigger":"change","description":"The proforma",
                "image_url":"${server.baseUrl}/img/full","thumb_url":"${server.baseUrl}/img/thumb","session_id":"s1","session_title":"Kick-off",
                "session_started_at":"2026-11-04T10:00:00Z"}],"next_cursor":null}"""
            )
        )
        server.on(
            "POST",
            "/api/clients/$PROJECT/ask",
            FakeServer.json(
                200,
                """{"data":{"answer":"40% in advance.","citations":[{"type":"call","session_id":"s1","title":"Payment terms","at_ms":31000,"quote":"forty"},
                {"type":"file","file_id":"f1","title":"Proforma March.pdf","page":2,"quote":"x"}]}}"""
            )
        )
        vm.refresh()
        pumpUntil("the projects") { vm.clients.value.size == 2 && vm.library.value.size == 2 }
    }
}
