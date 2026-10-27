<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\SpreadsheetConnection;

class SpreadsheetConnectionLinkIndicatorTest extends TestCase
{
    public function test_is_drive_connected_logic(): void
    {
        $conn = new SpreadsheetConnection();
        $this->assertFalse($conn->isDriveConnected());

        $conn->drive_folder_url = 'https://drive.google.com/drive/folders/1QRk_vowPYJfx2SmNkbg8-W2yy5o0nPRB';
        $conn->drive_folder_id = null;
        $this->assertFalse($conn->isDriveConnected());

        $conn->drive_folder_id = '1QRk_vowPYJfx2SmNkbg8-W2yy5o0nPRB';
        $this->assertTrue($conn->isDriveConnected());
    }

    public function test_is_spreadsheet_connected_logic(): void
    {
        $conn = new SpreadsheetConnection();
        $this->assertFalse($conn->isSpreadsheetConnected());

        $conn->spreadsheet_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit';
        $conn->spreadsheet_id = null;
        $this->assertFalse($conn->isSpreadsheetConnected());

        $conn->spreadsheet_id = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms';
        $this->assertTrue($conn->isSpreadsheetConnected());
    }

    public function test_has_unconnected_links_logic(): void
    {
        // Registration connection requires both spreadsheet and drive folder + connected status
        $regConn = new SpreadsheetConnection([
            'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit',
            'spreadsheet_id' => '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
            'connection_status' => SpreadsheetConnection::STATUS_CONNECTED,
        ]);

        // Drive is missing -> should have unconnected links
        $this->assertTrue($regConn->hasUnconnectedLinks());

        // Now add drive folder
        $regConn->drive_folder_url = 'https://drive.google.com/drive/folders/1QRk_vowPYJfx2SmNkbg8-W2yy5o0nPRB';
        $regConn->drive_folder_id = '1QRk_vowPYJfx2SmNkbg8-W2yy5o0nPRB';
        $this->assertFalse($regConn->hasUnconnectedLinks());

        // If connection status is error -> should have unconnected links
        $regConn->connection_status = SpreadsheetConnection::STATUS_ERROR;
        $this->assertTrue($regConn->hasUnconnectedLinks());
    }
}
