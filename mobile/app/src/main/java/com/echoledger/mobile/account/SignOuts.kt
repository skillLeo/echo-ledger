package com.echoledger.mobile.account

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

/**
 * Each sign-out on this phone (the pairing cleared): what an account page keeps beyond its screen (the two-step key and
 * recovery codes) is forgotten then, so it never comes back for whoever signs in next, the same email included.
 */
object SignOuts {
    private val count = MutableStateFlow(0)

    /** How many sign-outs since the app started; it goes up by one with each. */
    val signOuts: StateFlow<Int> = count.asStateFlow()

    fun happened() = count.update { it + 1 }
}
