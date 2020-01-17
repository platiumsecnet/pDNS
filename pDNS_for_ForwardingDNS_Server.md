fDNS for Forwarding DNS Server Purpose
---

# 1. Intro

A alternative take on developing a cache for client machines is through the use of a forwarding DNS server. This approach adds an additional link in the chain of DNS resolution by implementing a forwarding server that simply passes all requests to another DNS server with recursive capabilities (such as a caching DNS server).

The advantage of this system is that it can give you the advantage of a locally accessible cache while not having to do the recursive work (which can result in additional network traffic and can take up substantial resources on high traffic servers). This can also lead to some interesting flexibility in splitting your private and public traffic by forwarding to different servers.

A forwarding DNS server has the following properties:
- The ability to handle recursive requests without performing recursion itself. <br>
The most fundamental property of a forwarding DNS server is that it passes requests on to another agent for resolution. The forwarding server can have minimal resources and still provide great value by leveraging its cache.
- Provide a local cache at a closer network location. <br>
Particularly if you do not feel up to building, maintaining, and securing a full-fledged recursive DNS solution, a forwarding server can use public recursive DNS servers. It can leverage these servers while moving the primary caching location very close to the client machines. This can decrease answer times.
- Increases flexibility in defining local domain space. <br>
By passing requests to different servers conditionally, a forwarding server can ensure that internal requests are served by private servers while external requests use public DNS.
