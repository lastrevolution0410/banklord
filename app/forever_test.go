package main

import "testing"

func TestForeverClient(t *testing.T) {
	if interfaceNumber("1.60.1") != "16001" {
		t.Fatal("Forever interface number")
	}
	if clients["_forever_"] != "WoW Forever" {
		t.Fatal("Forever folder not recognised")
	}
}
