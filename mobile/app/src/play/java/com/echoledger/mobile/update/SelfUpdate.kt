package com.echoledger.mobile.update

import android.content.Context
import com.echoledger.mobile.data.AndroidReleaseDto
import com.echoledger.mobile.net.ApiClient
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * The Google Play build never updates itself (Play does, and Play's rules forbid any other way): this stub keeps the
 * same calls as the website build's updater and does nothing.
 */
object SelfUpdate {
    const val SUPPORTED = false

    private val _status = MutableStateFlow<UpdateStatus>(UpdateStatus.Idle)
    val status: StateFlow<UpdateStatus> = _status.asStateFlow()

    fun restore(context: Context) = Unit

    fun schedule(context: Context) = Unit

    suspend fun check(context: Context, api: ApiClient?, force: Boolean, nowMs: Long = 0L) = Unit

    suspend fun install(context: Context, api: ApiClient, release: AndroidReleaseDto) = Unit

    internal fun reset() = Unit
}
