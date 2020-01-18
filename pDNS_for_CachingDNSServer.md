fDNS for Caching DNS Server Purpose
---

# 1. Intro
A caching DNS server is a server that handles recursive requests from clients. Almost every DNS server that the operating system’s stub resolver will contact will be a caching DNS server.

Caching servers have the advantage of answering recursive requests from clients. While authoritative-only servers may be ideal for serving specific zone information, caching DNS servers are more broadly useful from a client’s perspective. They make the DNS system of the world accessible to rather dumb client interfaces.

To avoid having to take the performance hit of issuing multiple iterative request to other DNS servers every time it receives a recursive request, the server caches its results. This allows it to have access to a broad base of DNS information (the entire world’s publicly accessible DNS) while handling recent requests very quickly.

A caching DNS server has the following properties:
- Access to the entire range of public DNS data<br>
All zone data served by publicly accessible DNS servers hooked into the global delegation tree can be reached by a caching DNS server. It knows about the root DNS servers and can intelligently follow referrals as it receives data.
- Ability to spoon-feed data to dumb clients<br>
Almost every modern operating system offloads DNS resolution to dedicated recursive servers through the use of stub resolvers. These resolving libraries simply issue a recursive request and expect to be handed back a complete answer. A caching DNS server has the exact capabilities to serve these clients. By accepting a recursive query, these servers promise to either return with an answer or a DNS error message.
- Maintains a cache of recently requested data<br>
By caching the results as it collects them from other DNS servers for its client requests, a caching DNS server builds a cache for recent DNS data. Depending on how many clients use the server, how large the cache is, and how long the TTL data is on the DNS records themselves, this can drastically speed up DNS resolution in most cases.
