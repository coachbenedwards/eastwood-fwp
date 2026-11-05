# Newitts robot

Each weekday at 14:40 it asks eastwoodfc.com for the shirt orders paid since the last run,
checks Newitts has every size in stock at a normal price, puts them in the club's Newitts
basket with Express (next working day) delivery, and tells the website what it did.
Admin screen: Bookings -> Newitts orders.

It does not pay yet. The website emails orders@ at 14:50 with the list and the basket
total, and a person presses Pay before Newitts' 3pm cut-off (4pm in summer).

## Set up on the Mac (once)

    cd ~/eastwood-fwp/tools/newitts-robot
    npm install && npx playwright install chromium
    mkdir -p ~/.newitts-robot
    # the key is on Bookings -> Newitts orders
    echo '{"site":"https://eastwoodfc.com","key":"PASTE-ROBOT-KEY"}' > ~/.newitts-robot/config.json
    chmod 600 ~/.newitts-robot/config.json
    node robot.mjs --login        # sign in to Newitts in the window, then close it
    node robot.mjs --try          # reads stock only, adds nothing
    cp com.eastwoodfc.newitts-robot.plist ~/Library/LaunchAgents/
    launchctl load ~/Library/LaunchAgents/com.eastwoodfc.newitts-robot.plist

The Mac must be awake at 14:40 (System Settings -> Battery -> Schedule, or leave it plugged in).
If it isn't, the website emails the list at 14:50 anyway.

## When it stops on purpose

It stops, and the website emails orders@ with the reason, when: Newitts doesn't have enough
of a size; a price is over the limit; the day is over the shirt cap; the basket already had
something in it; it's signed out; or Newitts shows a robot check. It never tries to get
past a robot check.

Runs are logged in ~/.newitts-robot/runs/ with a screenshot of the basket.
