import "@testing-library/jest-dom/vitest";
import { configure } from "@testing-library/react";

// The suite often runs beside other heavy jobs on this laptop. A findBy / waitFor gave up after 1 s (the default), so
// under load the main window's start-up and pairing tests failed now and then while passing on their own. A wait that
// succeeds still ends at once; only a failing one now takes 3 s.
configure({ asyncUtilTimeout: 3000 });
