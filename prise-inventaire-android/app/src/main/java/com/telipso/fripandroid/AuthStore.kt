package com.telipso.fripandroid

import android.content.Context

/**
 * Persistance locale du token d'authentification + slug tenant.
 * (Baseline SharedPreferences ; à durcir en EncryptedSharedPreferences plus tard.)
 */
object AuthStore {
    private const val PREFS = "prise_auth"
    private const val KEY_TOKEN = "token"
    private const val KEY_SLUG = "slug"

    private fun prefs(ctx: Context) =
        ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun save(ctx: Context, token: String, slug: String) {
        prefs(ctx).edit()
            .putString(KEY_TOKEN, token)
            .putString(KEY_SLUG, slug)
            .apply()
    }

    fun token(ctx: Context): String? = prefs(ctx).getString(KEY_TOKEN, null)

    fun slug(ctx: Context): String? = prefs(ctx).getString(KEY_SLUG, null)

    fun isLoggedIn(ctx: Context): Boolean = !token(ctx).isNullOrBlank()

    fun clear(ctx: Context) {
        prefs(ctx).edit().clear().apply()
    }
}
