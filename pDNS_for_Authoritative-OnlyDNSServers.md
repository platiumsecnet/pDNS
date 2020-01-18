fDNS for Authoritative-Only DNS Servers Purpose
---

# 1.Intro

An authoritative-only DNS server is a server that only concerns itself with answering the queries for the zones that it is responsible for. Since it does not help resolve queries for outside zones, it is generally very fast and can handle many requests efficiently.

Authoritative-only servers have the following properties:
- Very fast at responding to queries for zones it controls.<br> 
An authoritative-only server will have all of the information about the domain it is responsible for, or referral information for zones within the domain that have been delegated out to other name servers.
- Will not respond to recursive queries<br>
The very definition of an authoritative-only server is one that does not handle recursive requests. This makes it a server only and never a client in the DNS system. Any request reaching an authoritative-only server will generally be coming from a resolver that has received a referral to it, meaning that the authoritative-only server will either have the full answer, or will be able to pass a new referral to the name server that it has delegated responsibility to.
- Does not cache query results.<br>
Since an authoritative-only server never queries other servers for information to resolve a request, it never has the opportunity to cache results. All of the information it knows is already in its system.
