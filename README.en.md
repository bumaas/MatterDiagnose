# Matter Diagnose

[![Checks](https://github.com/bumaas/MatterDiagnose/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/MatterDiagnose/actions/workflows/check.yml)

🇩🇪 [Deutsche Fassung](README.md)

A Symcon module that checks, with one click, why Matter devices will not join
your home or suddenly fall silent — especially with **Matter over Thread**. The
result is a traffic-light list in plain language: what is going on, what it
means, and what to do. Nothing is executed; the module only reads.

![Example report](docs/bericht.png)

## When do I need this?

**Pairing ends with "Failed" — and nothing else.**
Usually it fails in the last phase: the Symcon host cannot find the path into
the Thread radio network, has no IPv6, or the device is already connected to so
many systems that there is no room left. The diagnosis names the reason and,
where needed, the command that fixes it.

**A device shows "active" but no longer delivers values — while it keeps
working in the vendor app or in Home Assistant.**
Symcon does not show a lost connection on the instance: the status stays green,
the last value stays put. The diagnosis compares the devices paired in Symcon
with those actually announcing themselves on the network and tells you which one
is missing.

**After a restart or update, Thread devices are dead.**
Then the route into the Thread network is often missing, or the border router
has a new address. The diagnosis sees both.

## Installation and first run

1. Install the module — from the Module Store (beta channel) or via the module
   manager with `https://github.com/bumaas/MatterDiagnose.git`.
2. Create a **Matter Diagnose** instance (add instance → core instances).
3. Click **Start diagnosis** in the configuration form. A run takes up to
   25 seconds; the form shows the progress meanwhile.

The report appears as a list in the form and additionally as HTML in the
variable **Last report**, which can be embedded in any visualisation.

## How to read the report

Every finding carries a traffic light:

| Symbol | Meaning |
|---|---|
| ❌ | **Blocker** — no pairing will succeed and no device will run reliably like this. Always listed first. |
| ⚠️ | **Notice** — works right now, but will cause trouble (for example after the next restart), or evidence is missing. |
| ✅ | **OK** — with the detail that was checked, so you can verify it. |

Below each finding you read what it means and — if something needs doing — the
recommendation. Commands that need administrator rights (setting a route, for
instance) are **never executed by the module**; they are collected in the field
**Commands to run** for copying. That is deliberate: changing the routing table
is a conscious decision.

A run without a blocker does not mean everything is perfect — read the notices.
A run with a blocker does not mean everything is broken: fix the blocker, run
again, and the notices often resolve themselves.

## Continuous operation: monitoring and notification

The form has a **monitoring interval in minutes** (default 60, 0 = off). With
monitoring switched on, the check repeats in the background and writes its
result to status variables. No device is pinged in those runs, so
battery-powered devices stay asleep.

> Instances created before version 0.4 show 0 here — monitoring stays off until
> you enter a value.

| Variable | Meaning |
|---|---|
| Matter network OK | false as soon as a finding counts as a blocker |
| Paired devices / Devices announcing | expected and actual count |
| Thread border routers | number of border routers found |
| Last check | time of the last run |
| Last changes | plain text of what changed since the previous run — **written only on a real change** |
| Last report | the full report as HTML |

**Notification in three steps:** create a script, add an event **"On variable
update"** on the variable **Last changes** of the diagnosis instance, and pass
the change on in the script — the way you send messages anyway (push, e-mail,
Telegram …):

```php
<?php
// replace 12345 with the ID of the Matter Diagnose instance
$ok      = GetValueBoolean(IPS_GetObjectIDByIdent('Healthy', 12345));
$changes = GetValueString(IPS_GetObjectIDByIdent('Changes', 12345));
$text    = ($ok ? 'Matter network OK. ' : 'Matter network degraded! ') . $changes;
// put your own notification here, e.g.
IPS_LogMessage('Matter Diagnose', $text);
```

The event fires exactly when a device disappears or returns, a border router
drops out, or a finding appears or resolves — no hourly messages. The first run
reports nothing; it only records the baseline. The same applies to the first run
after updating to 0.4 build 31, because its format changed. If a known device is
missing in a run, the module asks once more before judging; a single lost packet does not
raise a false alarm. If device discovery fails completely for one run, only that
failure is reported — not every device as gone and every finding as resolved.
Whether a pairing window happens to be open does not trigger a message.

## The findings at a glance

**Is my Symcon host set up correctly?**
<!-- findings: no_ipv6 ipv6_ok mdns_silent mdns_ok -->
- IPv6 present or not (VPN adapters such as Tailscale or WireGuard do not count
  — Matter needs IPv6 on the home network).
- Does multicast get through? If not a single Matter service answers, the module
  sends a general query to tell "multicast blocked" from "no Matter on this
  network" (typical case: Docker without `--network host`). Answers from the
  host itself do not count.

**What is visible on the network?**
<!-- findings: no_border_router border_router_found operational_found commissionable_found no_commissionable no_commissionable_closed_only -->
- Thread border routers — the devices that connect the Thread radio network to
  the home network (Apple TV/HomePod, DIRIGERA, Google Nest, Home Assistant
  with OpenThread …). No border router, no Matter over Thread.
- Matter devices announcing themselves, and whether one is currently **open
  for pairing**. Devices that are visible but whose pairing window is closed
  are named separately — if you pressed the pairing button and read this, you
  know: the device is alive, the window just did not open (usually it already
  belongs to another system).

**Which devices are there at all?** — the device list (from 0.5)
- Below the findings there is one row per device: name (for your own devices the
  Symcon name with Id, otherwise the host name), connection (Thread or LAN/WLAN),
  power (battery, mains or unknown), one column per system with a tick — "Symcon"
  is this installation, "A", "B" … are the other systems in the network, whose ID
  and device count the line below gives —, the border router announcing it
  (labelled as in the finding "Thread border router found"; a LAN/WLAN device
  announces itself and has none) and its address.
- Other systems carry no name in their announcement; which one is Apple Home or
  DIRIGERA shows in which devices tick that column. Once recognised, name the
  system in the configuration under "Names for other systems" — its column is then
  labelled "Apple Home (A)" instead of "A" (from 0.5 build 41; since build 49 column
  and legend spell it the same way, and the picker shows only letter, ID and device
  count because the name sits next to it). A device Symcon knows
  but that has no tick under "Symcon" announces itself only for other systems —
  exactly the case in which Symcon does not find it again after a restart.
- The "Manufacturer" column (from 0.5 build 41) draws on three sources: Symcon for
  your own devices; for LAN/WLAN devices other services of the same device (Shelly,
  Philips Hue, Google Cast, HomeKit and ESPHome announce manufacturer and model);
  or the MAC address (vendor prefix, e.g. "Espressif" for an ESP chip) — either from
  the host name or, when that one is random, from the device's IPv6 address (from
  0.5 build 50). Other people's Thread devices stay numbers — their ID is random and the
  Matter announcement says nothing about the device.
- A hub that translates devices of other radio standards into Matter (the Aqara Hub
  M3 with its ZigBee devices, for one) announces each of them under its own name but
  with the hub's own address. Such devices carry the suffix "(via Aqara Hub M3)" and
  inherit its manufacturer (from 0.5 build 48); they stay a row of their own, because
  to Matter they are separate devices. The direction is only stated when it is proven
  — otherwise the field stays empty.
- This is not a finding but an inventory: it shows what is really visible on the
  network — including devices of other systems — and answers questions such as
  "which border router is this behind?" or "does this run on batteries?" at a glance.

**My Thread network uses public addresses — is it recognised?**
- Yes (from 0.5 build 45). If your router delegates a public IPv6 prefix, some
  border routers (the Aqara hub, for one) take an address range for the Thread
  network from it. The module recognises it when the border router names it in
  its announcement or announces the Thread devices on their behalf; a range
  without such evidence still does not count as a Thread network.

**Do my paired devices get through?**
<!-- findings: no_matter_controller no_own_devices fabric_unknown own_devices_visible own_devices_missing own_devices_missing_battery own_devices_silent_for_symcon own_devices_unsubscribed own_devices_ambiguous device_fabrics_full -->
- If a device does announce itself on the network, but only for other systems
  (Apple Home, Home Assistant) and not for Symcon, the report says exactly that
  (from 0.5 build 43): the device is alive, only the pairing with Symcon is stuck.
  What to do comes with it — check "Connected Systems" in the Matter configurator
  to see whether Symcon is still listed, otherwise pair again. This is recognised
  by the network name the module remembered from the last run in which the device
  was visible; a device that was never visible cannot be matched this way.
- Every device paired in Symcon is looked up on the network. If one does not
  announce itself, the finding names its connection state as Symcon sees it: if
  that says "OK", values keep coming in — a device can stop announcing itself
  without losing an established connection. So it is not urgent, but it does
  matter: the announcement is how Symcon finds a device again — after the next
  restart of Symcon, or with a new device address, re-establishing the connection
  can fail. It does not have to: in a field test a silent device kept delivering
  values after a restart. Only when the connection is gone too does this become a
  blocker.
- Battery-powered devices are marked with 🔋 in the list: they are allowed to stay
  silent most of the time, while a device on mains power should report in. The
  module recognises them by the battery values Symcon keeps for them, and
  otherwise by the device's last announcement.
- If there is no Matter controller yet or no paired device, the report says so
  instead of staying silent; if the module could not read your system's
  identifier or the mapping was ambiguous, that is stated as well.
- If a device's table of connected systems is full (five for most devices),
  every further pairing fails without a visible reason — the module warns
  beforehand.

**Is the path into the Thread network right?**
<!-- findings: thread_prefix_reachable thread_prefix_unreachable thread_prefix_no_reply thread_prefix_route_ok thread_prefix_untested thread_route_learned thread_route_learned_with_persistent thread_route_not_persistent thread_route_stale thread_route_gateway_unknown -->
- Is the Thread network reachable? A short ping to device addresses, gentle to
  sleeping devices: one failed attempt is "inconclusive", not an outage; in
  monitoring runs there is no ping at all, only the route counts.
- Where does the host get its route? On Windows the module reports whether it
  is **learned automatically** (nothing to do then) or only set by hand and
  gone after the next restart — including the command that makes it permanent.
  On Linux it recognises learned routes as well and leaves them alone.
- Outdated routes after the address range changed, and routes to border
  routers that no longer exist, are named with a delete command. The module only
  calls a route outdated when it knows which address ranges are in use — if not
  every device reports in completely during a run, the route is left unjudged
  rather than wrongly deleted.

**Is the Thread network healthy?**
<!-- findings: thread_network_ok thread_single_border_router thread_networks_split thread_partitions thread_dataset_mismatch -->
- Only one border router (if it fails, the whole network is gone), two
  separate Thread networks (typical when Apple and Google each opened their
  own), a network split into partitions, or border routers with different
  settings.

## Limits

- The diagnosis is **read-only**. Recommended commands are not executed.
- In Docker without `--network host` no multicast arrives — the diagnosis
  reports that as a finding, but cannot fix it.
- It says nothing about the **radio quality** inside the Thread network; that
  would need the interface of your own border router, which Apple and Google do
  not offer. A device counts as visible as soon as it announces itself.
- Matching the paired devices reads the configuration forms of the Matter core
  modules. If Symcon changes their layout, the module falls back to mapping via
  the device instances and tells you it could not read the fabric ID.
- Windows output is understood in German and English; for other languages the
  module reads the routing table by position, which usually works but is not
  verified for every language.

## Terms

| Term | Meaning |
|---|---|
| **Fabric** | A Matter system with its devices — Symcon is one, the Apple Home world another. A device can belong to several, but only to a limited number. |
| **Pairing window** | The period (usually 15 minutes) during which a device accepts new systems. Opened by button or app. |
| **Border router** | The device that connects Thread radio to the home network. Without it, Thread devices are unreachable. |
| **Subscription** | Symcon's standing connection to a device through which values arrive. If it is lost, the instance still shows "active". |
| **Route** | The entry telling the host that the Thread network's addresses are reached via the border router. |

---

## Appendix for the curious

### How the check works

The module queries three mDNS/DNS-SD services: `_meshcop._udp` (border
routers), `_matter._tcp` (commissioned devices) and `_matterc._udp`
(commissionable devices). Missing details — host names, IPv6 addresses, the
network data of the border routers, the TXT data on the commissioning mode — are
requested in up to three further rounds,
border routers first; a question once asked is not repeated. The total budget
of a run is 24 seconds, the reachability test gets the remainder — with as many
ping attempts as still fit without an answer. Readiness for
pairing is in the TXT key `CM` (0 = window closed, 1/2 = open) — some devices
announce with `CM=0` for minutes after booting, which is not a pairing window.

Only a device without an IPv4 address counts as a Thread device: Thread devices
reach the home network solely via IPv6 and the border router. A device with IPv4 —
a Shelly, a Hue bridge, a device from a mirrored neighbouring segment — sits on the
LAN, and its address range is not a Thread network even if it looks like one. In
addition to the multicast query, the module asks each border router directly: a
border router that announces the records of its Thread devices on their behalf may
answer the multicast query via multicast, which never reaches the module's port;
a query addressed to it is answered directly.

The module identifies its own fabric from the configuration forms of the Matter
core modules (fabric ID of the controller, node IDs of the devices) and looks
for the matching `<FabricID>-<NodeID>` announcements on the network. With several
controllers, each is matched against its own fabric and its own configurators.

### Thread details

The border routers reveal more in their `_meshcop` TXT records than pairing
needs: `xp` (extended PAN ID) and `nn` (network name) identify the Thread
network, `pt` the partition, `at` the active dataset, `sb` the backbone router
role, `tv` the Thread version. The network-health findings derive from these.
Vendors encode the partition ID in different byte orders; the comparison
tolerates that.

### Windows and the Thread route

The border router distributes the route into the Thread network via router
advertisements (route information option). Windows adopts it as long as the
home router allows foreign prefixes (FRITZ!Box: "Also allow IPv6 prefixes
announced by other IPv6 routers on the home network", effective after a reboot
of the box). In the routing table such routes look like manually set ones; only
their lifetime (`netsh interface ipv6 show route level=verbose`) tells them
apart: finite and continuously renewed for learned routes, "Infinite" for manual
ones. The module reads exactly that and reports learned routes as OK — an
additional persistent entry counts as a reserve for the time after a restart.
If Windows does not learn the route, the suggested command with
`store=persistent` helps; without that suffix it would be gone after the next
restart. On Linux the router advertisement renews the route itself; the module
recognises such routes by `proto ra`, or by `expires` on the SymBox, and never
calls them outdated.

### Not checked, and why

The controller's own announcement and the occupation of UDP port 5353 (after
consulting Symcon): as a Matter controller Symcon is a pure consumer and does
not announce itself; the mDNS port is held by Bonjour (Windows) or Avahi
(Linux), without which Symcon does not even start. Likewise not checked: the
number of foreign Matter systems on the network — no action follows from it.

### When asking for help: the debug window

If the report says something that does not match your setup, open the instance's
debug window in the console and run the diagnosis again. It shows everything the
module collected: which devices and border routers answered with which
addresses, what remained open after the follow-up queries, the routing table with
its assessment, the ping results, and the devices paired in Symcon with their
subscription and battery state. That excerpt is what helps when asking in the
forum.

### Tests

```
php tests/run_tests.php
php tests/check_locale.php
```

The unit tests run without Symcon: mDNS parser, route assessment and finding
logic are checked against real packet captures, real system output and scenario
fixtures (`tests/fixtures/`). One test also holds this README and the German
version against the finding catalogue — each group of findings carries a
`<!-- findings: … -->` comment for that purpose. `tests/capture_fixtures.php`
collects fresh captures from your own LAN when needed.
