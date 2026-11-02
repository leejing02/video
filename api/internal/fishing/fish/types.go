package fish

// FishType holds the static config for each fish variant.
type FishType struct {
	ID   int
	Coin int
}

// Types mirrors the JS fish type table (id 1-12).
var Types = map[int]FishType{
	1:  {1, 2},
	2:  {2, 3},
	3:  {3, 5},
	4:  {4, 8},
	5:  {5, 10},
	6:  {6, 20},
	7:  {7, 30},
	8:  {8, 40},
	9:  {9, 50},
	10: {10, 60},
	11: {11, 100},
	12: {12, 200},
}
