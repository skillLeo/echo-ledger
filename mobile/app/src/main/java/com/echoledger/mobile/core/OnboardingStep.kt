package com.echoledger.mobile.core

/** The steps of the first-run wizard (B19), in order. */
enum class OnboardingStep(val title: String) {
    Welcome("Welcome"),
    Permissions("Permissions"),
    Pair("Pair"),
    Test("Test"),
    Done("Done");

    /** "Step 2 of 5". */
    val label: String get() = "Step ${ordinal + 1} of ${entries.size}"

    val next: OnboardingStep get() = entries[minOf(ordinal + 1, entries.lastIndex)]

    val previous: OnboardingStep get() = entries[maxOf(ordinal - 1, 0)]

    companion object {
        /** A saved step name, or the first step for anything unknown. */
        fun of(name: String?): OnboardingStep = entries.firstOrNull { it.name == name } ?: Welcome
    }
}
