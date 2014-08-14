##Questions you may have if deploying Get Outside!

* _Can I have Places and/or Events automatically reload every day?_ Yes; see Automatic-Reloading.txt for istructions on how to use your scheduled task system (cron) to have Events and Places reload as often as you like.

* _Can event links on the Calendar page open in a nice little window?_ Not reliably. Google Calendar events disallow embedding and must be opened in a new window, while ActiveNet's TOU explicitly forbid linking inside a panel like that.

* _Why isn't there a driver for this-or-that Data Source or Event Source?_ Because we didn't think of it, and nobody suggested it. :) Development was largely to meet some real-world needs of our funding clients, so centered around their data and processes. If you need support for some other type of service, contact us and we'll see about it, or write it yourself! See the _WritingDrivers.txt_ document in the _docs_ subfolder.

* _I picked Google Maps as a basemap and it worked fine, but broke itself._ Google Maps API changes quite often, and these breakages are a perennial problem. Report it, we'll see whether we can push out a patch in a reasonable time. In the meantime, switch to a different basemap option.

