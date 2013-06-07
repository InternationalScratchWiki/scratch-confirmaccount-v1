Complete online documenation:
Original version: http://www.mediawiki.org/wiki/Extension:ConfirmAccount
Modified version: http://wiki.scratch.mit.edu/wiki/User:Jvvg/Authentication

== Breaking changes ==
=== MediaWiki 1.20 ===
$wgAccountRequestMinWords, $wgAccountRequestToS, $wgAccountRequestExtraInfo,
and $wgAllowAccountRequestFiles were all folded into a new variable called
$wgConfirmAccountRequestFormItems.

=== Modified version ===
Gutted all email functionality - the user is given their temporary password when they register rather than when their request is accepted, and added a tool to verify registrations against the Scratch website.

== Licensing ==
Original version © GPL, Aaron Schulz
Modified version also available under GPL, by Jacob G.