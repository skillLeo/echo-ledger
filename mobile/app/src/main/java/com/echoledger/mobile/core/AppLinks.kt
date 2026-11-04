package com.echoledger.mobile.core

import android.net.Uri

/**
 * Links that open in the app (since v1.5, pure, unit-tested): "Open in EchoLedger" on a web page
 * (`echoledger://open?link=<url>`) and the server's own share, project and request links (`https://<host>/s/…`, `/p/…`,
 * `/r/…`, as Android App Links). Only https links with one of those paths are taken; anything else is ignored.
 */
object AppLinks {
    private val PATHS = listOf("/s/", "/p/", "/r/")

    /** The share, project or request URL an incoming VIEW intent carries; null for anything else. */
    fun link(action: String?, data: Uri?): String? {
        if (action != android.content.Intent.ACTION_VIEW || data == null) return null
        val raw = if (data.scheme == "echoledger") data.getQueryParameter("link") else data.toString()
        return raw?.takeIf(::isLink)
    }

    /** An https URL whose path is a share (/s/), project (/p/) or request (/r/) page. */
    fun isLink(url: String): Boolean {
        val uri = runCatching { Uri.parse(url) }.getOrNull() ?: return false
        val path = uri.path.orEmpty()
        return uri.scheme == "https" && !uri.host.isNullOrBlank() && PATHS.any { path.startsWith(it) && path.length > it.length }
    }
}
