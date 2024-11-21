Teltonika FM Data Parser Server
This repository contains a TCP server implementation for processing data from Teltonika GPS devices. The server uses the [uro/teltonika-fm-parser](https://github.com/uro/teltonika-fm-parser) package to parse incoming data packets and store AVL data in JSON format.

Features
Handles TCP connections from Teltonika GPS devices.
Parses device IMEI and AVL data packets.
Stores parsed AVL data in a JSON file (packet_data.json) organized by IMEI.
Utilizes the [uro/teltonika-fm-parser](https://github.com/uro/teltonika-fm-parser) package for parsing.
