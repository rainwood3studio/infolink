<?php

use App\Mcp\Servers\InfolinkServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/infolink', InfolinkServer::class)->middleware('auth:sanctum');
