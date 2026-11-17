CELESTIA GEMS — MOBILE API (complete folder)
============================================

Replace public_html/api entirely with this folder. It is the full set, so
there is nothing to merge and nothing to track — delete the old api folder
and upload this one.

NOTHING TO CONFIGURE
--------------------
Every endpoint reads your existing functions/ folder: db_connection.php for
Supabase, paymongo_config.php for payments, helpers.php for the base URL.
One set of credentials, shared with the website. cg_base_url() takes the
host from each request, so this works on the live domain and on XAMPP with
no edits.

WHAT CHANGED SINCE THE FIRST UPLOAD
-----------------------------------
checkout.php          REPLACED — was Maya, now PayMongo
                      Uses create_paymongo_checkout_session(), checks
                      'payment_paymongo_enabled', accepts "cod"|"paymongo",
                      stores the session id in payments.transaction_id.

payment-return.php    NEW — PayMongo's return target for the app.
                      Handles both ?order_id= and ?booking_id=. Does the
                      same verification as the web handlers but without
                      $_SESSION, which a browser opened from the app has
                      no way to carry.

maya-return.php       DELETED — nothing references it.

book.php              NEW — create a service booking.
booking-slots.php     NEW — the day's appointment grid with availability.
bookings.php          NEW — the customer's bookings + upcoming count.
notifications.php     NEW — list, and mark read.
places.php            NEW — Google Places/Geocoding proxy, so the address
                      autocomplete works in the app without the API key
                      shipping inside it. Uses the same two Google APIs
                      the website already calls, and sends your own site
                      URL as the referrer so the existing key restriction
                      keeps working unchanged.

DATABASE
--------
api_tokens must exist (you already created it). Nothing else is needed:
bookings use service_bookings and notifications use notifications, both of
which the website already writes to.

ENDPOINTS
---------
Public:
    products.php          ?search= ?category_id= ?page= ?per_page=
    product-detail.php    ?id=
    categories.php
    services.php
    about.php
    booking-slots.php     ?date=YYYY-MM-DD

Auth:
    register.php · login.php · verify-2fa.php · resend-2fa.php
    logout.php · me.php · profile-update.php

Shopping (Bearer token):
    cart.php              GET POST PUT DELETE
    wishlist.php          GET list, POST toggle
    checkout.php          POST  -> order_id + checkout_url for PayMongo
    orders.php · order-detail.php    ?order_id=
    payment-return.php    PayMongo's redirect target (not called by the app)

Services (Bearer token):
    book.php              POST { service_ids, appointment_date,
                                 appointment_time, payment_method, notes? }
    bookings.php          GET

Other:
    notifications.php     GET list · POST {} or { notification_id }
    places.php            ?action=autocomplete&q= · details&place_id= · reverse&lat=&lng=
    ai-chat.php           POST { message, history? }

QUICK TEST
----------
    https://YOURDOMAIN/api/products.php
    https://YOURDOMAIN/api/booking-slots.php?date=2026-10-10

Both are public, so a browser is enough. The first proves the deployment,
the second proves the booking tables are reachable.

STILL NOT IN THE API
--------------------
Posting a product review. product-detail.php returns reviews but nothing
accepts a new one, and the web only offers that form to someone who bought
and received the item — so it needs that purchase check too.
