package ws

import "testing"

func TestPrivateUpdatesOnlyReachMembers(t *testing.T) {
	h := NewHub()
	a := &Client{userID: 1, send: make(chan []byte, 5)}
	b := &Client{userID: 2, send: make(chan []byte, 5)}
	outsider := &Client{userID: 3, send: make(chan []byte, 5)}
	h.rooms[0] = map[*Client]struct{}{a: {}, b: {}, outsider: {}}
	h.Notify([]int64{1, 2}, "message.sent", "private")
	if len(a.send) != 1 || len(b.send) != 1 || len(outsider.send) != 0 {
		t.Fatal("private event leaked or member missed event")
	}
	h.Notify([]int64{}, "message.sent", "nobody")
	if len(a.send) != 1 || len(outsider.send) != 0 {
		t.Fatal("empty member list must not broadcast")
	}
	h.Notify(nil, "message.sent", "global")
	if len(a.send) != 2 || len(b.send) != 2 || len(outsider.send) != 1 {
		t.Fatal("global event not delivered")
	}
}
