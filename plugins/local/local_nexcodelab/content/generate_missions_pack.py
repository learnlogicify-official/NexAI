#!/usr/bin/env python3
"""Generate missions_pack_50.xml — 50 NexCodeLab Mission Lab missions.

Uses pandas (and sklearn where needed) to compute frame/metric expects.
"""
from __future__ import annotations

import xml.etree.ElementTree as ET
from collections import Counter
from pathlib import Path
from typing import Any

import pandas as pd

try:
    from sklearn.feature_extraction.text import CountVectorizer
    from sklearn.linear_model import LinearRegression, LogisticRegression
    from sklearn.model_selection import train_test_split
except ImportError as e:  # pragma: no cover
    raise SystemExit("Need pandas and scikit-learn: pip install pandas scikit-learn") from e

OUT = Path(__file__).resolve().parent / "missions_pack_50.xml"
STARTER = "import pandas as pd\n"


def csv_of(df: pd.DataFrame) -> str:
    return df.to_csv(index=False)


def metric4(x: float) -> str:
    return f"{float(x):.4f}"


def sig_frame(fn: str, doc: str) -> str:
    return (
        f"def {fn}(df: pd.DataFrame) -> pd.DataFrame:\n"
        f'    """{doc}"""\n'
        f"    return df\n"
    )


def sig_metric(fn: str, doc: str) -> str:
    return (
        f"def {fn}(df: pd.DataFrame) -> float:\n"
        f'    """{doc}"""\n'
        f"    return 0.0\n"
    )


def sig_sklearn(imports: str, body: str) -> str:
    return imports.rstrip() + "\n\n" + body


def brief(title: str, situation: str, columns: list[tuple[str, str, str]], done: list[str]) -> str:
    rows = "\n".join(f"| `{c}` | {t} | {m} |" for c, t, m in columns)
    done_lines = "\n".join(f"{i}. {d}" for i, d in enumerate(done, 1))
    return (
        f"# {title}\n\n"
        f"## Situation\n{situation}\n\n"
        f"## Data dictionary (`data.csv`)\n"
        f"Each row is one record in this extract. Columns:\n\n"
        f"| Column | Type | Meaning |\n"
        f"|--------|------|---------|\n"
        f"{rows}\n\n"
        f'## What "done" looks like\n'
        f"Work **step by step**. Each Check grades one helper in `main.py`.\n\n"
        f"{done_lines}\n\n"
        f"Do not invent extra columns or rename fields unless a step asks for it.\n"
    )


def instr(goal: str, rule: str, fn: str, check: str) -> str:
    return (
        f"<p><strong>Goal:</strong> {goal}</p>"
        f"<p>{rule}</p>"
        f"<p>Implement <code>{fn}</code>.</p>"
        f"<p><strong>Check:</strong> {check}</p>"
    )


def step(
    title: str,
    instructions: str,
    hint: str,
    kind: str,
    fn: str,
    signature: str,
    *,
    preprocess: str | None = None,
    expect_csv: str | None = None,
    expect: str | None = None,
    floor: float | None = None,
    xp: int = 25,
) -> dict[str, Any]:
    s: dict[str, Any] = {
        "title": title,
        "instructions": instructions,
        "hint": hint,
        "checkkind": kind,
        "fn": fn,
        "signature": signature,
        "xp": xp,
    }
    if preprocess:
        s["preprocess"] = preprocess
    if expect_csv is not None:
        s["expect_csv"] = expect_csv
    if expect is not None:
        s["expect"] = expect
    if floor is not None:
        s["floor"] = floor
    return s


def mission(
    *,
    name: str,
    slug: str,
    scenario: str,
    track: str,
    coverkey: str,
    estimateminutes: int,
    brief_md: str,
    data: pd.DataFrame,
    steps: list[dict[str, Any]],
) -> dict[str, Any]:
    return {
        "name": name,
        "slug": slug,
        "scenario": scenario,
        "track": track,
        "coverkey": coverkey,
        "estimateminutes": estimateminutes,
        "status": "published",
        "brief": brief_md,
        "starter": STARTER,
        "data": csv_of(data),
        "steps": steps,
    }


# ---------------------------------------------------------------------------
# Mission builders (50 unique themes)
# ---------------------------------------------------------------------------


def m01_retail() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "sku": ["A1", "A2", "B1", "B2", "C1", "C2", "A3", "B3"],
            "category": ["apparel", "apparel", "home", "home", "beauty", "beauty", "apparel", "home"],
            "units": [12, 5, 3, 8, 20, 2, 15, 6],
            "unit_price": [24.0, 40.0, 55.0, 30.0, 12.0, 80.0, 18.0, 45.0],
        }
    )
    with_rev = raw.copy()
    with_rev["revenue"] = with_rev["units"] * with_rev["unit_price"]
    cat = (
        with_rev.groupby("category", as_index=False)["revenue"]
        .sum()
        .rename(columns={"revenue": "total_revenue"})
        .sort_values("category")
        .reset_index(drop=True)
    )
    top = float(with_rev["revenue"].max())
    return mission(
        name="Retail shelf pulse",
        slug="retail-shelf-pulse",
        scenario="A mid-market retailer needs SKU revenue before reallocating floor space this weekend.",
        track="wrangling",
        coverkey="sales",
        estimateminutes=30,
        brief_md=brief(
            "Retail shelf pulse",
            "Merchandising wants a clean revenue view by SKU and category before the weekend reset.",
            [
                ("sku", "text", "Stock-keeping unit code"),
                ("category", "text", "Merchandising category"),
                ("units", "integer", "Units sold in the period"),
                ("unit_price", "number", "Price per unit in USD"),
            ],
            [
                "Add a revenue column as units × unit price",
                "Summarize total revenue by category",
                "Report the single highest SKU revenue",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Build revenue",
                instr(
                    "Finance needs line-level revenue on every SKU.",
                    "Create a <code>revenue</code> column equal to units sold times unit price. Keep all existing columns.",
                    "add_revenue(df)",
                    "Returned frame includes <code>revenue</code> with correct product values.",
                ),
                "Line revenue is quantity sold multiplied by the listed price.",
                "frame",
                "add_revenue",
                sig_frame("add_revenue", "Add revenue = units * unit_price."),
                expect_csv=csv_of(with_rev),
                xp=25,
            ),
            step(
                "Category totals",
                instr(
                    "Category managers want rolled-up revenue.",
                    "After revenue exists, return a two-column table <code>category,total_revenue</code> with sums, sorted by category name.",
                    "category_revenue(df)",
                    "Category totals match the period extract.",
                ),
                "Roll revenue up to each category label, then order those labels alphabetically.",
                "frame",
                "category_revenue",
                sig_frame("category_revenue", "Return category,total_revenue sorted by category."),
                preprocess="add_revenue",
                expect_csv=csv_of(cat),
                xp=30,
            ),
            step(
                "Top SKU revenue",
                instr(
                    "Ops wants the peak line revenue as a single number.",
                    "On the revenue-enriched frame, return the maximum <code>revenue</code> as a float.",
                    "peak_revenue(df)",
                    "Float matches the highest SKU revenue to 4 decimals.",
                ),
                "Find the largest revenue value among all SKUs.",
                "metric",
                "peak_revenue",
                sig_metric("peak_revenue", "Max revenue after add_revenue."),
                preprocess="add_revenue",
                expect=metric4(top),
                floor=top * 0.99,
                xp=20,
            ),
        ],
    )


def m02_hr() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "emp_id": [101, 102, 103, 104, 105, 106, 107, 108],
            "dept": ["Eng", "Eng", "Sales", "Sales", "HR", "Eng", "Sales", "HR"],
            "salary": [92000, 88000, 72000, 69000, 64000, 110000, 75000, 61000],
            "tenure_yrs": [3.0, 1.5, 4.0, 0.5, 6.0, 8.0, 2.0, 1.0],
        }
    )
    filtered = raw[raw["tenure_yrs"] >= 2].reset_index(drop=True)
    dept_avg = (
        filtered.groupby("dept", as_index=False)["salary"]
        .mean()
        .rename(columns={"salary": "avg_salary"})
        .sort_values("dept")
        .reset_index(drop=True)
    )
    overall = float(filtered["salary"].mean())
    return mission(
        name="HR tenure pay desk",
        slug="hr-tenure-pay-desk",
        scenario="People Ops is auditing pay for employees with at least two years tenure ahead of compensation planning.",
        track="wrangling",
        coverkey="clinic",
        estimateminutes=28,
        brief_md=brief(
            "HR tenure pay desk",
            "Compensation planning needs a tenure-filtered payroll slice and department averages.",
            [
                ("emp_id", "integer", "Employee identifier"),
                ("dept", "text", "Department code"),
                ("salary", "integer", "Annual salary in USD"),
                ("tenure_yrs", "number", "Years with the company"),
            ],
            [
                "Keep only employees with tenure of 2+ years",
                "Average salary by department on that slice",
                "Report overall average salary on the filtered roster",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Tenure filter",
                instr(
                    "Exclude short-tenure staff from this audit.",
                    "Keep rows where <code>tenure_yrs</code> is at least 2. Preserve column order.",
                    "tenured_staff(df)",
                    "Only 2+ year employees remain.",
                ),
                "Drop anyone below the two-year tenure threshold.",
                "frame",
                "tenured_staff",
                sig_frame("tenured_staff", "Keep tenure_yrs >= 2."),
                expect_csv=csv_of(filtered),
                xp=25,
            ),
            step(
                "Dept averages",
                instr(
                    "Leaders want department average pay on the filtered roster.",
                    "Return <code>dept,avg_salary</code> sorted by department. The grader applies the tenure filter first.",
                    "dept_avg_salary(df)",
                    "Department averages match the filtered extract.",
                ),
                "Average salary within each department label, then sort department names.",
                "frame",
                "dept_avg_salary",
                sig_frame("dept_avg_salary", "Return dept,avg_salary sorted by dept."),
                preprocess="tenured_staff",
                expect_csv=csv_of(dept_avg),
                xp=30,
            ),
            step(
                "Overall average",
                instr(
                    "Finance wants one number for the filtered cohort.",
                    "Return the mean salary on the tenure-filtered frame as a float.",
                    "overall_avg_salary(df)",
                    "Mean salary to 4 decimals.",
                ),
                "Average all remaining salaries into one figure.",
                "metric",
                "overall_avg_salary",
                sig_metric("overall_avg_salary", "Mean salary after tenure filter."),
                preprocess="tenured_staff",
                expect=metric4(overall),
                floor=overall * 0.95,
                xp=20,
            ),
        ],
    )


def m03_hotels() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "booking_id": [1, 2, 3, 4, 5, 6, 7, 8],
            "city": ["Austin", "Austin", "Denver", "Denver", "Miami", "Miami", "Austin", "Denver"],
            "nights": [2, 3, 1, 4, 2, 5, 1, 2],
            "nightly_rate": [140, 160, 110, 125, 200, 180, 150, 130],
            "status": ["ok", "ok", "cancel", "ok", "ok", "ok", "cancel", "ok"],
        }
    )
    active = raw[raw["status"] == "ok"].copy().reset_index(drop=True)
    active["stay_value"] = active["nights"] * active["nightly_rate"]
    city = (
        active.groupby("city", as_index=False)["stay_value"]
        .sum()
        .rename(columns={"stay_value": "city_value"})
        .sort_values("city")
        .reset_index(drop=True)
    )
    cancel_rate = float((raw["status"] == "cancel").mean())
    return mission(
        name="Hotel booking ledger",
        slug="hotel-booking-ledger",
        scenario="A regional hotel group needs stay value on confirmed bookings and a cancellation rate for ops standup.",
        track="wrangling",
        coverkey="house",
        estimateminutes=32,
        brief_md=brief(
            "Hotel booking ledger",
            "Revenue management is cleaning last week's bookings before the Monday forecast.",
            [
                ("booking_id", "integer", "Booking identifier"),
                ("city", "text", "Property city"),
                ("nights", "integer", "Length of stay"),
                ("nightly_rate", "integer", "Rate charged per night"),
                ("status", "text", "`ok` confirmed or `cancel` cancelled"),
            ],
            [
                "Keep confirmed bookings and add stay_value",
                "Sum stay value by city",
                "Report overall cancellation rate on the raw extract",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Confirmed stay value",
                instr(
                    "Only confirmed stays should drive revenue tables.",
                    "Keep rows with status <code>ok</code>, then add <code>stay_value</code> = nights × nightly_rate.",
                    "confirmed_value(df)",
                    "Cancelled rows removed; stay_value present.",
                ),
                "Remove cancelled bookings, then multiply nights by the nightly rate.",
                "frame",
                "confirmed_value",
                sig_frame("confirmed_value", "Filter status==ok; add stay_value."),
                expect_csv=csv_of(active),
                xp=30,
            ),
            step(
                "City rollup",
                instr(
                    "City GMs want total confirmed stay value.",
                    "Return <code>city,city_value</code> sorted by city. Grader applies confirmed_value first.",
                    "city_totals(df)",
                    "City sums match confirmed stays.",
                ),
                "Add stay values within each city, then sort city names.",
                "frame",
                "city_totals",
                sig_frame("city_totals", "Return city,city_value sorted by city."),
                preprocess="confirmed_value",
                expect_csv=csv_of(city),
                xp=25,
            ),
            step(
                "Cancel rate",
                instr(
                    "Ops wants the share of bookings that cancelled.",
                    "On the raw frame, return the fraction of rows with status <code>cancel</code> as a float between 0 and 1.",
                    "cancel_rate(df)",
                    "Cancellation share to 4 decimals.",
                ),
                "Count cancelled bookings as a share of all bookings.",
                "metric",
                "cancel_rate",
                sig_metric("cancel_rate", "Fraction status==cancel."),
                expect=metric4(cancel_rate),
                floor=0.2,
                xp=20,
            ),
        ],
    )


def m04_bikes() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "trip_id": list(range(1, 9)),
            "member_type": ["member", "casual", "member", "member", "casual", "casual", "member", "casual"],
            "duration_min": [12, 45, 8, 22, 60, 15, 18, 90],
            "distance_km": [2.1, 5.0, 1.4, 3.2, 7.5, 2.0, 2.8, 9.0],
        }
    )
    short = raw[raw["duration_min"] <= 30].reset_index(drop=True)
    by_type = (
        short.groupby("member_type", as_index=False)["distance_km"]
        .mean()
        .rename(columns={"distance_km": "avg_km"})
        .sort_values("member_type")
        .reset_index(drop=True)
    )
    share = float((raw["member_type"] == "member").mean())
    return mission(
        name="Bike share triage",
        slug="bike-share-triage",
        scenario="A city bike-share desk wants short-trip quality metrics before adjusting dock inventory.",
        track="eda",
        coverkey="eda",
        estimateminutes=25,
        brief_md=brief(
            "Bike share triage",
            "Ops is reviewing trips of 30 minutes or less for dock planning.",
            [
                ("trip_id", "integer", "Trip identifier"),
                ("member_type", "text", "`member` or `casual` rider"),
                ("duration_min", "integer", "Trip length in minutes"),
                ("distance_km", "number", "Distance ridden in kilometers"),
            ],
            [
                "Keep trips lasting 30 minutes or less",
                "Average distance by member type on short trips",
                "Report the share of all trips taken by members",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Short trips",
                instr(
                    "Focus on trips that fit a commute-style window.",
                    "Keep rows where <code>duration_min</code> is at most 30.",
                    "short_trips(df)",
                    "Only ≤30 minute trips remain.",
                ),
                "Exclude longer recreational rides above the 30-minute cutoff.",
                "frame",
                "short_trips",
                sig_frame("short_trips", "Keep duration_min <= 30."),
                expect_csv=csv_of(short),
                xp=25,
            ),
            step(
                "Distance by type",
                instr(
                    "Compare member vs casual short-trip distances.",
                    "Return <code>member_type,avg_km</code> sorted by member_type. Grader applies short_trips first.",
                    "avg_distance_by_type(df)",
                    "Average kilometers per rider type.",
                ),
                "Average distance within each rider type label.",
                "frame",
                "avg_distance_by_type",
                sig_frame("avg_distance_by_type", "Return member_type,avg_km sorted."),
                preprocess="short_trips",
                expect_csv=csv_of(by_type),
                xp=30,
            ),
            step(
                "Member share",
                instr(
                    "Leadership wants member mix on the full extract.",
                    "Return the fraction of rows with member_type <code>member</code>.",
                    "member_share(df)",
                    "Member share to 4 decimals.",
                ),
                "Members as a share of every trip in the extract.",
                "metric",
                "member_share",
                sig_metric("member_share", "Fraction member_type==member."),
                expect=metric4(share),
                floor=0.4,
                xp=20,
            ),
        ],
    )


def m05_delivery() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "order_id": [11, 12, 13, 14, 15, 16, 17, 18],
            "courier": ["Lee", "Pat", "Lee", "Sam", "Pat", "Sam", "Lee", "Pat"],
            "promised_min": [30, 35, 25, 40, 30, 45, 28, 32],
            "actual_min": [28, 42, 24, 39, 31, 50, 35, 29],
        }
    )
    tagged = raw.copy()
    tagged["late"] = (tagged["actual_min"] > tagged["promised_min"]).astype(int)
    late_only = tagged[tagged["late"] == 1].reset_index(drop=True)
    late_rate = float(tagged["late"].mean())
    return mission(
        name="Delivery SLA desk",
        slug="delivery-sla-desk",
        scenario="A last-mile delivery ops lead needs late flags and courier late volume before the SLA review.",
        track="wrangling",
        coverkey="lab",
        estimateminutes=30,
        brief_md=brief(
            "Delivery SLA desk",
            "Promise times vs actual delivery times need a clear late flag for Friday's SLA review.",
            [
                ("order_id", "integer", "Order identifier"),
                ("courier", "text", "Courier name"),
                ("promised_min", "integer", "Promised minutes from dispatch"),
                ("actual_min", "integer", "Actual minutes until delivery"),
            ],
            [
                "Flag late deliveries where actual exceeds promised",
                "List only late rows",
                "Report overall late rate",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Late flag",
                instr(
                    "Mark each order as late or on time.",
                    "Add integer column <code>late</code>: 1 when actual_min is greater than promised_min, else 0.",
                    "flag_late(df)",
                    "late column matches the SLA rule.",
                ),
                "A delivery is late only when actual time exceeds the promise.",
                "frame",
                "flag_late",
                sig_frame("flag_late", "Add late = 1 if actual_min > promised_min else 0."),
                expect_csv=csv_of(tagged),
                xp=25,
            ),
            step(
                "Late orders only",
                instr(
                    "Dispatchers need the late queue.",
                    "After flagging, keep only rows where late is 1.",
                    "late_orders(df)",
                    "Only late deliveries remain.",
                ),
                "Filter to the late-flagged rows.",
                "frame",
                "late_orders",
                sig_frame("late_orders", "Keep late==1."),
                preprocess="flag_late",
                expect_csv=csv_of(late_only),
                xp=25,
            ),
            step(
                "Late rate",
                instr(
                    "Leadership wants the late share.",
                    "On the flagged frame, return the mean of <code>late</code> as a float.",
                    "late_rate(df)",
                    "Late rate to 4 decimals.",
                ),
                "Average the late flags to get the late fraction.",
                "metric",
                "late_rate",
                sig_metric("late_rate", "Mean of late after flag_late."),
                preprocess="flag_late",
                expect=metric4(late_rate),
                floor=0.3,
                xp=25,
            ),
        ],
    )


def m06_returns() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "return_id": list(range(1, 9)),
            "channel": ["web", "store", "web", "web", "store", "web", "store", "web"],
            "reason": ["size", "damaged", "size", "other", "damaged", "size", "other", "damaged"],
            "refund_usd": [40, 120, 55, 30, 90, 48, 25, 110],
        }
    )
    size = raw[raw["reason"] == "size"].reset_index(drop=True)
    by_ch = (
        size.groupby("channel", as_index=False)["refund_usd"]
        .sum()
        .rename(columns={"refund_usd": "refund_total"})
        .sort_values("channel")
        .reset_index(drop=True)
    )
    size_share = float((raw["reason"] == "size").mean())
    return mission(
        name="Returns reason audit",
        slug="returns-reason-audit",
        scenario="Customer care is digging into size-related returns before adjusting the sizing chart.",
        track="wrangling",
        coverkey="sales",
        estimateminutes=26,
        brief_md=brief(
            "Returns reason audit",
            "Product wants refund exposure for size-related returns by channel.",
            [
                ("return_id", "integer", "Return identifier"),
                ("channel", "text", "`web` or `store` origin"),
                ("reason", "text", "Return reason code"),
                ("refund_usd", "number", "Refund amount in USD"),
            ],
            [
                "Isolate size-related returns",
                "Sum refunds by channel for those returns",
                "Report size-return share of all returns",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Size returns",
                instr(
                    "Focus the audit on sizing issues.",
                    "Keep rows where reason is <code>size</code>.",
                    "size_returns(df)",
                    "Only size-reason rows remain.",
                ),
                "Exclude returns that are not about size.",
                "frame",
                "size_returns",
                sig_frame("size_returns", "Keep reason==size."),
                expect_csv=csv_of(size),
                xp=25,
            ),
            step(
                "Channel refunds",
                instr(
                    "Compare size refunds by channel.",
                    "Return <code>channel,refund_total</code> sorted by channel. Grader applies size_returns first.",
                    "size_refunds_by_channel(df)",
                    "Channel refund totals match.",
                ),
                "Add refunds within each channel for the filtered returns.",
                "frame",
                "size_refunds_by_channel",
                sig_frame("size_refunds_by_channel", "Return channel,refund_total sorted."),
                preprocess="size_returns",
                expect_csv=csv_of(by_ch),
                xp=30,
            ),
            step(
                "Size share",
                instr(
                    "How common are size returns overall?",
                    "Return the fraction of all returns with reason <code>size</code>.",
                    "size_share(df)",
                    "Share to 4 decimals.",
                ),
                "Size reasons as a share of every return.",
                "metric",
                "size_share",
                sig_metric("size_share", "Fraction reason==size."),
                expect=metric4(size_share),
                floor=0.3,
                xp=20,
            ),
        ],
    )


def m07_energy() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "meter_id": ["M1", "M2", "M3", "M4", "M5", "M6", "M7", "M8"],
            "site": ["plant", "plant", "office", "office", "plant", "warehouse", "warehouse", "office"],
            "kwh": [1200, 980, 210, 180, 4500, 400, 380, 195],
        }
    )
    q1 = raw["kwh"].quantile(0.25)
    q3 = raw["kwh"].quantile(0.75)
    iqr = q3 - q1
    lo, hi = q1 - 1.5 * iqr, q3 + 1.5 * iqr
    clean = raw[(raw["kwh"] >= lo) & (raw["kwh"] <= hi)].reset_index(drop=True)
    kept = float(len(clean))
    return mission(
        name="Energy spike watch",
        slug="energy-spike-watch",
        scenario="Facilities flagged a meter spike. Confirm with Tukey fences before dispatching an engineer.",
        track="eda",
        coverkey="eda",
        estimateminutes=22,
        brief_md=brief(
            "Energy spike watch",
            "A warehouse meter looks extreme. Leadership wants an IQR-cleaned view and kept count.",
            [
                ("meter_id", "text", "Meter identifier"),
                ("site", "text", "Building type"),
                ("kwh", "number", "Kilowatt-hours in the window"),
            ],
            [
                "Keep meters within 1.5×IQR fences on kwh",
                "Report how many meters remain after filtering",
            ],
        ),
        data=raw,
        steps=[
            step(
                "IQR filter",
                instr(
                    "Remove extreme kWh readings with Tukey fences.",
                    "Keep rows whose <code>kwh</code> lies in [Q1 − 1.5·IQR, Q3 + 1.5·IQR] using the sample quartiles.",
                    "iqr_clean_kwh(df)",
                    "Outlier meter(s) removed; others unchanged.",
                ),
                "Use the standard 1.5 times interquartile range fences on consumption.",
                "frame",
                "iqr_clean_kwh",
                sig_frame("iqr_clean_kwh", "Keep kwh within Tukey fences."),
                expect_csv=csv_of(clean),
                xp=35,
            ),
            step(
                "Kept meters",
                instr(
                    "How many meters survive the fence?",
                    "Return the number of remaining rows as a float. Grader applies iqr_clean_kwh first.",
                    "kept_meters(df)",
                    "Count of cleaned meters.",
                ),
                "Count the rows that remain after the fence.",
                "metric",
                "kept_meters",
                sig_metric("kept_meters", "len(df) after IQR filter."),
                preprocess="iqr_clean_kwh",
                expect=metric4(kept),
                floor=kept - 0.1,
                xp=20,
            ),
        ],
    )


def m08_hospitals() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "patient_id": list(range(1, 9)),
            "ward": ["A", "A", "B", "B", "C", "C", "A", "B"],
            "los_days": [3, 5, 2, 8, 4, 1, 6, 3],
            "readmit_30d": [0, 1, 0, 1, 0, 0, 1, 0],
        }
    )
    long = raw[raw["los_days"] >= 4].reset_index(drop=True)
    by_ward = (
        long.groupby("ward", as_index=False)["readmit_30d"]
        .mean()
        .rename(columns={"readmit_30d": "readmit_rate"})
        .sort_values("ward")
        .reset_index(drop=True)
    )
    rate = float(long["readmit_30d"].mean())
    return mission(
        name="Hospital length-of-stay desk",
        slug="hospital-los-desk",
        scenario="Quality nursing wants readmit rates for longer stays before the monthly case review.",
        track="eda",
        coverkey="clinic",
        estimateminutes=28,
        brief_md=brief(
            "Hospital length-of-stay desk",
            "Focus on patients with length of stay of 4+ days and their 30-day readmit flags.",
            [
                ("patient_id", "integer", "Patient identifier"),
                ("ward", "text", "Ward code"),
                ("los_days", "integer", "Length of stay in days"),
                ("readmit_30d", "0 / 1", "1 if readmitted within 30 days"),
            ],
            [
                "Keep stays of 4+ days",
                "Average readmit flag by ward on that cohort",
                "Report overall readmit rate for the cohort",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Longer stays",
                instr(
                    "Limit the review to longer admissions.",
                    "Keep rows where <code>los_days</code> is at least 4.",
                    "long_stays(df)",
                    "Only 4+ day stays remain.",
                ),
                "Drop short stays below four days.",
                "frame",
                "long_stays",
                sig_frame("long_stays", "Keep los_days >= 4."),
                expect_csv=csv_of(long),
                xp=25,
            ),
            step(
                "Ward readmit rates",
                instr(
                    "Compare wards on the long-stay cohort.",
                    "Return <code>ward,readmit_rate</code> sorted by ward. Grader applies long_stays first.",
                    "ward_readmit_rate(df)",
                    "Ward rates match the filtered patients.",
                ),
                "Average the readmit flag within each ward.",
                "frame",
                "ward_readmit_rate",
                sig_frame("ward_readmit_rate", "Return ward,readmit_rate sorted."),
                preprocess="long_stays",
                expect_csv=csv_of(by_ward),
                xp=30,
            ),
            step(
                "Cohort readmit rate",
                instr(
                    "One number for the case review slide.",
                    "Return the mean of <code>readmit_30d</code> on the long-stay frame.",
                    "cohort_readmit_rate(df)",
                    "Overall rate to 4 decimals.",
                ),
                "Average readmit flags across the filtered cohort.",
                "metric",
                "cohort_readmit_rate",
                sig_metric("cohort_readmit_rate", "Mean readmit_30d after long_stays."),
                preprocess="long_stays",
                expect=metric4(rate),
                floor=0.3,
                xp=20,
            ),
        ],
    )


def m09_marketing() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "ad_id": list(range(1, 9)),
            "channel": ["email", "social", "email", "social", "email", "social", "email", "social"],
            "copy": [
                "great deal love this offer",
                "bad spam hate inbox",
                "good savings love",
                "terrible clickbait hate",
                "great value good",
                "bad timing hate",
                "love exclusive good perk",
                "terrible waste hate",
            ],
        }
    )
    POS, NEG = {"good", "great", "love"}, {"bad", "hate", "terrible"}

    def score_text(t: str) -> int:
        toks = t.lower().split()
        return sum(1 for w in toks if w in POS) - sum(1 for w in toks if w in NEG)

    scores = pd.DataFrame({"ad_id": raw["ad_id"], "score": [score_text(t) for t in raw["copy"]]})
    vec = CountVectorizer()
    vec.fit(raw["copy"])
    vocab = float(len(vec.vocabulary_))
    return mission(
        name="Marketing copy sentiment",
        slug="marketing-copy-sentiment",
        scenario="Growth marketing wants a cheap lexicon score on ad copy before A/B tests.",
        track="nlp",
        coverkey="nlp",
        estimateminutes=30,
        brief_md=brief(
            "Marketing copy sentiment",
            "Score ad copy with POS={good,great,love} and NEG={bad,hate,terrible}, then measure vocabulary size.",
            [
                ("ad_id", "integer", "Ad identifier"),
                ("channel", "text", "Channel name"),
                ("copy", "text", "Ad copy text"),
            ],
            [
                "Return ad_id,score from the lexicon net",
                "Fit CountVectorizer on copy; return vocabulary size",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Lexicon scores",
                instr(
                    "Net POS minus NEG word hits in each copy line.",
                    "Tokenize lowercase on whitespace. Return <code>ad_id,score</code>.",
                    "lexicon_scores(df)",
                    "Scores match the lexicon rule.",
                ),
                "Reward positive cue words and penalize negative ones, then net them per ad.",
                "frame",
                "lexicon_scores",
                sig_frame("lexicon_scores", "POS/NEG lexicon; return ad_id,score."),
                expect_csv=csv_of(scores),
                xp=30,
            ),
            step(
                "Vocabulary size",
                instr(
                    "How wide is the copy vocabulary?",
                    "Fit CountVectorizer on copy; return vocabulary size as a float.",
                    "vocab_size(df)",
                    "Vocabulary size matches.",
                ),
                "Build a bag-of-words vocabulary over all copy lines and count unique tokens.",
                "metric",
                "vocab_size",
                sig_sklearn(
                    "from sklearn.feature_extraction.text import CountVectorizer",
                    sig_metric("vocab_size", "CountVectorizer vocabulary size."),
                ),
                expect=metric4(vocab),
                floor=vocab - 1,
                xp=25,
            ),
        ],
    )


def m10_inventory() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "item": ["bolt", "nut", "washer", "bracket", "screw", "hinge", "clip", "pin"],
            "on_hand": [40, 12, 5, 8, 60, 3, 25, 2],
            "reorder_point": [20, 15, 10, 10, 30, 5, 20, 5],
            "unit_cost": [0.5, 0.2, 0.1, 4.0, 0.15, 6.0, 0.8, 1.5],
        }
    )
    tagged = raw.copy()
    tagged["needs_reorder"] = (tagged["on_hand"] < tagged["reorder_point"]).astype(int)
    need = tagged[tagged["needs_reorder"] == 1].reset_index(drop=True)
    n_need = float(tagged["needs_reorder"].sum())
    return mission(
        name="Inventory reorder desk",
        slug="inventory-reorder-desk",
        scenario="Warehouse planning needs a reorder flag list before the weekly purchase order.",
        track="wrangling",
        coverkey="lab",
        estimateminutes=24,
        brief_md=brief(
            "Inventory reorder desk",
            "Flag items below reorder point and count how many need purchasing.",
            [
                ("item", "text", "Part name"),
                ("on_hand", "integer", "Units currently in stock"),
                ("reorder_point", "integer", "Minimum stock before reorder"),
                ("unit_cost", "number", "Cost per unit"),
            ],
            [
                "Flag items where on_hand is below reorder_point",
                "List only items that need reorder",
                "Report how many items need reorder",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Reorder flag",
                instr(
                    "Mark stockouts risk clearly.",
                    "Add <code>needs_reorder</code> as 1 when on_hand is strictly below reorder_point, else 0.",
                    "flag_reorder(df)",
                    "Flag column matches the reorder rule.",
                ),
                "An item needs reorder only when stock is below the reorder point.",
                "frame",
                "flag_reorder",
                sig_frame("flag_reorder", "Add needs_reorder flag."),
                expect_csv=csv_of(tagged),
                xp=25,
            ),
            step(
                "Reorder list",
                instr(
                    "Buyers need the action list.",
                    "Keep rows where needs_reorder is 1. Grader applies flag_reorder first.",
                    "reorder_list(df)",
                    "Only flagged items remain.",
                ),
                "Keep the rows marked as needing reorder.",
                "frame",
                "reorder_list",
                sig_frame("reorder_list", "Keep needs_reorder==1."),
                preprocess="flag_reorder",
                expect_csv=csv_of(need),
                xp=25,
            ),
            step(
                "Reorder count",
                instr(
                    "How many SKUs need action?",
                    "Return the sum of <code>needs_reorder</code> as a float on the flagged frame.",
                    "reorder_count(df)",
                    "Count of items needing reorder.",
                ),
                "Add up the reorder flags.",
                "metric",
                "reorder_count",
                sig_metric("reorder_count", "Sum needs_reorder."),
                preprocess="flag_reorder",
                expect=metric4(n_need),
                floor=n_need - 0.1,
                xp=20,
            ),
        ],
    )


def m11_weather() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "day": list(range(1, 9)),
            "temp_c": [18, 21, 19, 35, 17, 16, 22, 20],
            "rain_mm": [0, 2, 5, 0, 12, 0, 1, 3],
            "city": ["Port"] * 8,
        }
    )
    hot = raw[raw["temp_c"] >= 20].reset_index(drop=True)
    rain_days = float((raw["rain_mm"] > 0).sum())
    avg_hot = float(hot["temp_c"].mean())
    return mission(
        name="Weather week digest",
        slug="weather-week-digest",
        scenario="A local news desk wants warm-day stats and rainy-day counts for the weekend segment.",
        track="eda",
        coverkey="eda",
        estimateminutes=20,
        brief_md=brief(
            "Weather week digest",
            "Editors need warm days (≥20°C) and how many days saw any rain.",
            [
                ("day", "integer", "Day number in the week"),
                ("temp_c", "number", "High temperature in Celsius"),
                ("rain_mm", "number", "Rainfall in millimeters"),
                ("city", "text", "City label"),
            ],
            [
                "Keep days with temperature at least 20°C",
                "Average temperature on those warm days",
                "Count days with any rain on the full week",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Warm days",
                instr(
                    "Focus the segment on warmer days.",
                    "Keep rows where <code>temp_c</code> is at least 20.",
                    "warm_days(df)",
                    "Only warm days remain.",
                ),
                "Drop cooler days below twenty degrees.",
                "frame",
                "warm_days",
                sig_frame("warm_days", "Keep temp_c >= 20."),
                expect_csv=csv_of(hot),
                xp=25,
            ),
            step(
                "Warm average",
                instr(
                    "What was the average warm-day high?",
                    "Return the mean of <code>temp_c</code> on the warm-day frame.",
                    "avg_warm_temp(df)",
                    "Average to 4 decimals.",
                ),
                "Average temperatures among the remaining warm days.",
                "metric",
                "avg_warm_temp",
                sig_metric("avg_warm_temp", "Mean temp_c after warm_days."),
                preprocess="warm_days",
                expect=metric4(avg_hot),
                floor=avg_hot - 1,
                xp=25,
            ),
            step(
                "Rainy day count",
                instr(
                    "How many days had rain?",
                    "On the raw frame, return the count of days with rain_mm greater than 0 as a float.",
                    "rainy_day_count(df)",
                    "Count of rainy days.",
                ),
                "Count days where rainfall is positive.",
                "metric",
                "rainy_day_count",
                sig_metric("rainy_day_count", "Count rain_mm > 0."),
                expect=metric4(rain_days),
                floor=rain_days - 0.1,
                xp=20,
            ),
        ],
    )


def m12_sports() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "player": ["Ada", "Ben", "Cara", "Dan", "Eve", "Finn", "Gia", "Hank"],
            "team": ["Fox", "Fox", "Owl", "Owl", "Fox", "Owl", "Fox", "Owl"],
            "points": [12, 8, 15, 10, 20, 7, 14, 18],
            "minutes": [28, 22, 30, 25, 32, 18, 27, 31],
        }
    )
    tagged = raw.copy()
    tagged["pp30"] = tagged["points"] / tagged["minutes"] * 30
    fox = tagged[tagged["team"] == "Fox"].reset_index(drop=True)
    fox_avg = float(fox["pp30"].mean())
    return mission(
        name="Sports scoring desk",
        slug="sports-scoring-desk",
        scenario="A league analytics intern must pace-adjust scoring before the coach film session.",
        track="wrangling",
        coverkey="lab",
        estimateminutes=28,
        brief_md=brief(
            "Sports scoring desk",
            "Coaches want points per 30 minutes, then Fox team averages.",
            [
                ("player", "text", "Player name"),
                ("team", "text", "Team name"),
                ("points", "integer", "Points scored"),
                ("minutes", "integer", "Minutes played"),
            ],
            [
                "Add points-per-30-minutes pace",
                "Keep Fox players",
                "Average Fox pace",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Pace column",
                instr(
                    "Normalize scoring to a 30-minute pace.",
                    "Add <code>pp30</code> = points / minutes × 30.",
                    "add_pp30(df)",
                    "pp30 values match the pace formula.",
                ),
                "Scale each player's scoring to a thirty-minute equivalent.",
                "frame",
                "add_pp30",
                sig_frame("add_pp30", "Add pp30 = points/minutes*30."),
                expect_csv=csv_of(tagged),
                xp=25,
            ),
            step(
                "Fox roster",
                instr(
                    "Film session is Fox-only tonight.",
                    "Keep rows where team is <code>Fox</code>. Grader applies add_pp30 first.",
                    "fox_players(df)",
                    "Only Fox players remain.",
                ),
                "Filter to the Fox team.",
                "frame",
                "fox_players",
                sig_frame("fox_players", "Keep team==Fox."),
                preprocess="add_pp30",
                expect_csv=csv_of(fox),
                xp=25,
            ),
            step(
                "Fox average pace",
                instr(
                    "One number for the coaching slide.",
                    "Return the mean of <code>pp30</code> on the Fox frame.",
                    "fox_avg_pp30(df)",
                    "Average pace to 4 decimals.",
                ),
                "Average the pace values for remaining Fox players.",
                "metric",
                "fox_avg_pp30",
                sig_metric("fox_avg_pp30", "Mean pp30 for Fox."),
                preprocess="fox_players",
                expect=metric4(fox_avg),
                floor=fox_avg * 0.9,
                xp=25,
            ),
        ],
    )


def m13_grades() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "student_id": [1, 2, 3, 4, 5, 6, 7, 8],
            "course": ["DS101", "DS101", "DS101", "ML200", "ML200", "ML200", "DS101", "ML200"],
            "midterm": [72, 88, 65, 90, 70, 55, 94, 80],
            "final": [78, 85, 60, 92, 74, 58, 90, 83],
        }
    )
    tagged = raw.copy()
    tagged["avg"] = (tagged["midterm"] + tagged["final"]) / 2
    passers = tagged[tagged["avg"] >= 70].reset_index(drop=True)
    pass_rate = float((tagged["avg"] >= 70).mean())
    return mission(
        name="Course grades desk",
        slug="course-grades-desk",
        scenario="An academic coordinator needs passing averages before advising week.",
        track="eda",
        coverkey="eda",
        estimateminutes=26,
        brief_md=brief(
            "Course grades desk",
            "Build a simple average of midterm and final, then review who passed (≥70).",
            [
                ("student_id", "integer", "Student identifier"),
                ("course", "text", "Course code"),
                ("midterm", "integer", "Midterm score"),
                ("final", "integer", "Final score"),
            ],
            [
                "Add average of midterm and final",
                "Keep students with average at least 70",
                "Report pass rate on all students",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Average score",
                instr(
                    "Create a single average grade per student.",
                    "Add <code>avg</code> as the mean of midterm and final.",
                    "add_avg(df)",
                    "avg equals (midterm+final)/2.",
                ),
                "Blend midterm and final with equal weight.",
                "frame",
                "add_avg",
                sig_frame("add_avg", "Add avg = (midterm+final)/2."),
                expect_csv=csv_of(tagged),
                xp=25,
            ),
            step(
                "Passing students",
                instr(
                    "Advising wants the passing roster.",
                    "Keep rows where avg is at least 70. Grader applies add_avg first.",
                    "passing_students(df)",
                    "Only averages ≥70 remain.",
                ),
                "Keep students at or above the seventy threshold.",
                "frame",
                "passing_students",
                sig_frame("passing_students", "Keep avg >= 70."),
                preprocess="add_avg",
                expect_csv=csv_of(passers),
                xp=25,
            ),
            step(
                "Pass rate",
                instr(
                    "What share of students passed?",
                    "On the avg-enriched full frame, return the fraction with avg ≥ 70.",
                    "pass_rate(df)",
                    "Pass rate to 4 decimals.",
                ),
                "Passing students as a share of everyone.",
                "metric",
                "pass_rate",
                sig_metric("pass_rate", "Fraction avg >= 70 after add_avg."),
                preprocess="add_avg",
                expect=metric4(pass_rate),
                floor=0.5,
                xp=25,
            ),
        ],
    )


def m14_banking() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "acct_id": [201, 202, 203, 204, 205, 206, 207, 208],
            "product": ["checking", "savings", "checking", "savings", "checking", "savings", "checking", "savings"],
            "balance": [1200, 5000, 80, 15000, 450, 2200, 3000, 900],
            "fee_flag": [0, 0, 1, 0, 1, 0, 0, 1],
        }
    )
    fee = raw[raw["fee_flag"] == 1].reset_index(drop=True)
    by_prod = (
        raw.groupby("product", as_index=False)["balance"]
        .mean()
        .rename(columns={"balance": "avg_balance"})
        .sort_values("product")
        .reset_index(drop=True)
    )
    fee_share = float(raw["fee_flag"].mean())
    return mission(
        name="Banking fee watch",
        slug="banking-fee-watch",
        scenario="Retail banking compliance needs fee-flagged accounts and product average balances.",
        track="eda",
        coverkey="clinic",
        estimateminutes=27,
        brief_md=brief(
            "Banking fee watch",
            "Review accounts that incurred a fee and overall product balances.",
            [
                ("acct_id", "integer", "Account identifier"),
                ("product", "text", "Product type"),
                ("balance", "number", "Current balance"),
                ("fee_flag", "0 / 1", "1 if a fee was charged this period"),
            ],
            [
                "List fee-flagged accounts",
                "Average balance by product",
                "Report fee incidence rate",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Fee accounts",
                instr(
                    "Pull the fee-flagged list.",
                    "Keep rows where fee_flag is 1.",
                    "fee_accounts(df)",
                    "Only fee-flagged accounts remain.",
                ),
                "Exclude accounts without a fee this period.",
                "frame",
                "fee_accounts",
                sig_frame("fee_accounts", "Keep fee_flag==1."),
                expect_csv=csv_of(fee),
                xp=25,
            ),
            step(
                "Product averages",
                instr(
                    "Product managers want average balances.",
                    "Return <code>product,avg_balance</code> sorted by product on the full extract.",
                    "avg_balance_by_product(df)",
                    "Product averages match.",
                ),
                "Average balances within each product type.",
                "frame",
                "avg_balance_by_product",
                sig_frame("avg_balance_by_product", "Return product,avg_balance sorted."),
                expect_csv=csv_of(by_prod),
                xp=30,
            ),
            step(
                "Fee rate",
                instr(
                    "What share of accounts saw a fee?",
                    "Return the mean of fee_flag as a float.",
                    "fee_rate(df)",
                    "Fee incidence to 4 decimals.",
                ),
                "Average the fee flags across accounts.",
                "metric",
                "fee_rate",
                sig_metric("fee_rate", "Mean fee_flag."),
                expect=metric4(fee_share),
                floor=0.3,
                xp=20,
            ),
        ],
    )


def m15_telecom() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "customer_id": list(range(1, 9)),
            "plan": ["basic", "plus", "basic", "plus", "basic", "plus", "basic", "plus"],
            "data_gb": [2.0, 8.0, 1.5, 12.0, 3.0, 9.5, 0.8, 11.0],
            "churn": [1, 0, 1, 0, 0, 0, 1, 0],
        }
    )
    heavy = raw[raw["data_gb"] >= 5].reset_index(drop=True)
    X = raw[["data_gb"]]
    y = raw["churn"]
    Xtr, Xte, ytr, yte = train_test_split(X, y, test_size=0.25, random_state=0)
    shapes = pd.DataFrame({"n_train": [len(Xtr)], "n_test": [len(Xte)]})
    clf = LogisticRegression()
    clf.fit(Xtr, ytr)
    acc = float(clf.score(Xte, yte))
    return mission(
        name="Telecom churn clinic",
        slug="telecom-churn-clinic",
        scenario="A telecom analyst must rebuild an honest train/test split and logistic baseline on a tiny churn sample.",
        track="ml",
        coverkey="clinic",
        estimateminutes=40,
        brief_md=brief(
            "Telecom churn clinic",
            "Predict churn from data usage with a proper split and logistic baseline.",
            [
                ("customer_id", "integer", "Customer identifier"),
                ("plan", "text", "Plan name"),
                ("data_gb", "number", "Data used in GB"),
                ("churn", "0 / 1", "1 if customer churned"),
            ],
            [
                "Report train/test sizes for a 25% holdout",
                "Fit logistic regression and report test accuracy",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Split shapes",
                instr(
                    "Prove the holdout sizes before modeling.",
                    "Using feature <code>data_gb</code> and target <code>churn</code>, split with test_size=0.25 and random_state=0. Return a one-row frame <code>n_train,n_test</code>.",
                    "split_shapes(df)",
                    "Train/test counts match the fixed split.",
                ),
                "Reserve one quarter of rows for testing with a fixed seed so sizes are reproducible.",
                "frame",
                "split_shapes",
                sig_sklearn(
                    "from sklearn.model_selection import train_test_split",
                    sig_frame("split_shapes", "test_size=0.25, random_state=0 → n_train,n_test."),
                ),
                expect_csv=csv_of(shapes),
                xp=25,
            ),
            step(
                "Logistic accuracy",
                instr(
                    "Hit a logistic baseline on the same split.",
                    "Train LogisticRegression on the train split of data_gb→churn (test_size=0.25, random_state=0). Return test accuracy as a float.",
                    "logistic_accuracy(df)",
                    "Test accuracy meets the floor and expected value.",
                ),
                "Fit a logistic model on the training rows and score it on the held-out rows.",
                "metric",
                "logistic_accuracy",
                sig_sklearn(
                    "from sklearn.model_selection import train_test_split\nfrom sklearn.linear_model import LogisticRegression",
                    sig_metric("logistic_accuracy", "Test accuracy of LogisticRegression."),
                ),
                expect=metric4(acc),
                floor=min(0.5, acc),
                xp=40,
            ),
        ],
    )


def m16_logistics() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "shipment_id": list(range(1, 9)),
            "lane": ["east", "east", "west", "west", "east", "west", "east", "west"],
            "weight_kg": [10, 25, 12, 40, 8, 30, 15, 22],
            "cost_usd": [40, 90, 50, 130, 35, 110, 55, 80],
        }
    )
    tagged = raw.copy()
    tagged["cost_per_kg"] = tagged["cost_usd"] / tagged["weight_kg"]
    east = tagged[tagged["lane"] == "east"].reset_index(drop=True)
    east_avg = float(east["cost_per_kg"].mean())
    return mission(
        name="Logistics cost intensity",
        slug="logistics-cost-intensity",
        scenario="A freight desk needs cost-per-kg by lane before renegotiating carrier rates.",
        track="wrangling",
        coverkey="ship",
        estimateminutes=29,
        brief_md=brief(
            "Logistics cost intensity",
            "Normalize shipment cost by weight, then review east-lane intensity.",
            [
                ("shipment_id", "integer", "Shipment identifier"),
                ("lane", "text", "Lane name"),
                ("weight_kg", "number", "Shipment weight"),
                ("cost_usd", "number", "Total shipping cost"),
            ],
            [
                "Add cost per kilogram",
                "Keep east-lane shipments",
                "Average east cost-per-kg",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Cost per kg",
                instr(
                    "Normalize cost by weight.",
                    "Add <code>cost_per_kg</code> = cost_usd / weight_kg.",
                    "add_cost_per_kg(df)",
                    "cost_per_kg matches the ratio.",
                ),
                "Divide total cost by weight for each shipment.",
                "frame",
                "add_cost_per_kg",
                sig_frame("add_cost_per_kg", "Add cost_per_kg."),
                expect_csv=csv_of(tagged),
                xp=25,
            ),
            step(
                "East lane",
                instr(
                    "Today's negotiation is east-only.",
                    "Keep lane <code>east</code> rows. Grader applies add_cost_per_kg first.",
                    "east_shipments(df)",
                    "Only east shipments remain.",
                ),
                "Filter to the east lane.",
                "frame",
                "east_shipments",
                sig_frame("east_shipments", "Keep lane==east."),
                preprocess="add_cost_per_kg",
                expect_csv=csv_of(east),
                xp=25,
            ),
            step(
                "East average intensity",
                instr(
                    "One intensity number for east.",
                    "Return the mean of cost_per_kg on the east frame.",
                    "east_avg_cpk(df)",
                    "Average to 4 decimals.",
                ),
                "Average cost-per-kg among remaining east shipments.",
                "metric",
                "east_avg_cpk",
                sig_metric("east_avg_cpk", "Mean cost_per_kg for east."),
                preprocess="east_shipments",
                expect=metric4(east_avg),
                floor=east_avg * 0.9,
                xp=25,
            ),
        ],
    )


def m17_streaming() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "title_id": list(range(1, 9)),
            "genre": ["drama", "comedy", "drama", "comedy", "action", "action", "drama", "comedy"],
            "blurb": [
                "good drama love story",
                "bad comedy hate jokes",
                "great acting love",
                "terrible pacing hate",
                "good action great",
                "bad CGI hate",
                "love ending good",
                "terrible plot hate",
            ],
        }
    )
    POS, NEG = {"good", "great", "love"}, {"bad", "hate", "terrible"}

    def score_text(t: str) -> int:
        toks = t.lower().split()
        return sum(1 for w in toks if w in POS) - sum(1 for w in toks if w in NEG)

    scores = pd.DataFrame({"title_id": raw["title_id"], "score": [score_text(t) for t in raw["blurb"]]})
    pos = scores[scores["score"] > 0].reset_index(drop=True)
    avg = float(scores["score"].mean())
    return mission(
        name="Streaming blurb sentiment",
        slug="streaming-blurb-sentiment",
        scenario="Content ops wants lexicon scores on title blurbs before renewing licenses.",
        track="nlp",
        coverkey="nlp",
        estimateminutes=28,
        brief_md=brief(
            "Streaming blurb sentiment",
            "Score blurbs with POS={good,great,love} and NEG={bad,hate,terrible}.",
            [
                ("title_id", "integer", "Title identifier"),
                ("genre", "text", "Genre label"),
                ("blurb", "text", "Short viewer blurb"),
            ],
            ["Return title_id,score", "Keep positive scores", "Average score overall"],
        ),
        data=raw,
        steps=[
            step(
                "Lexicon scores",
                instr(
                    "Net POS minus NEG hits per blurb.",
                    "Tokenize lowercase on whitespace. Return <code>title_id,score</code>.",
                    "lexicon_scores(df)",
                    "Scores match the lexicon.",
                ),
                "Reward positive cue words and penalize negative ones, then net them.",
                "frame",
                "lexicon_scores",
                sig_frame("lexicon_scores", "POS/NEG lexicon; return title_id,score."),
                expect_csv=csv_of(scores),
                xp=30,
            ),
            step(
                "Positive blurbs",
                instr(
                    "Renewals care about positive titles first.",
                    "Keep score > 0. Grader applies lexicon_scores first.",
                    "positive_scores(df)",
                    "Only positive scores remain.",
                ),
                "Keep blurbs whose net score is above zero.",
                "frame",
                "positive_scores",
                sig_frame("positive_scores", "Keep score > 0."),
                preprocess="lexicon_scores",
                expect_csv=csv_of(pos),
                xp=25,
            ),
            step(
                "Average score",
                instr(
                    "Overall blurb sentiment.",
                    "Return mean score on the scored frame.",
                    "avg_score(df)",
                    "Average to 4 decimals.",
                ),
                "Average net scores across all blurbs.",
                "metric",
                "avg_score",
                sig_metric("avg_score", "Mean score after lexicon_scores."),
                preprocess="lexicon_scores",
                expect=metric4(avg),
                floor=avg - 0.5,
                xp=20,
            ),
        ],
    )


def m18_agriculture() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "plot_id": list(range(1, 9)),
            "crop": ["corn", "corn", "wheat", "wheat", "corn", "wheat", "corn", "wheat"],
            "rainfall_mm": [40, 55, 30, 70, 20, 45, 60, 25],
            "yield_t": [4.2, 5.1, 2.8, 3.9, 3.0, 3.2, 5.5, 2.5],
        }
    )
    wet = raw[raw["rainfall_mm"] >= 40].reset_index(drop=True)
    corr = float(abs(raw["rainfall_mm"].corr(raw["yield_t"])))
    return mission(
        name="Agriculture yield desk",
        slug="agriculture-yield-desk",
        scenario="An agronomy analyst needs wet-plot yields and rainfall–yield correlation for a co-op briefing.",
        track="eda",
        coverkey="eda",
        estimateminutes=25,
        brief_md=brief(
            "Agriculture yield desk",
            "Review plots with rainfall ≥40mm and the absolute correlation of rainfall vs yield.",
            [
                ("plot_id", "integer", "Plot identifier"),
                ("crop", "text", "Crop type"),
                ("rainfall_mm", "number", "Season rainfall"),
                ("yield_t", "number", "Yield in tonnes"),
            ],
            [
                "Keep wetter plots (≥40mm)",
                "Report absolute Pearson correlation of rainfall and yield on all plots",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Wet plots",
                instr(
                    "Focus on wetter plots for the briefing.",
                    "Keep rows where rainfall_mm is at least 40.",
                    "wet_plots(df)",
                    "Only wetter plots remain.",
                ),
                "Exclude drier plots below forty millimeters.",
                "frame",
                "wet_plots",
                sig_frame("wet_plots", "Keep rainfall_mm >= 40."),
                expect_csv=csv_of(wet),
                xp=25,
            ),
            step(
                "Rain–yield link",
                instr(
                    "How tightly do rainfall and yield move together?",
                    "Return the absolute Pearson correlation of rainfall_mm and yield_t on the full extract as a float.",
                    "abs_rain_yield_corr(df)",
                    "Absolute correlation to 4 decimals.",
                ),
                "Measure the strength of association between rainfall and yield, ignoring sign.",
                "metric",
                "abs_rain_yield_corr",
                sig_metric("abs_rain_yield_corr", "abs(corr(rainfall_mm, yield_t))."),
                expect=metric4(corr),
                floor=max(0.0, corr - 0.2),
                xp=30,
            ),
        ],
    )


def m19_airlines() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "flight_id": list(range(1, 9)),
            "route": ["SFO-LAX", "SFO-LAX", "DEN-ORD", "DEN-ORD", "SFO-LAX", "DEN-ORD", "SFO-LAX", "DEN-ORD"],
            "delay_min": [0, 25, 5, 40, 12, 0, 55, 8],
            "seats_sold": [140, 160, 120, 150, 155, 130, 170, 125],
        }
    )
    delayed = raw[raw["delay_min"] >= 15].reset_index(drop=True)
    delay_rate = float((raw["delay_min"] >= 15).mean())
    avg_delay = float(delayed["delay_min"].mean())
    return mission(
        name="Airline delay desk",
        slug="airline-delay-desk",
        scenario="An airline ops center needs delayed-flight stats before the evening irregular-ops huddle.",
        track="eda",
        coverkey="ship",
        estimateminutes=24,
        brief_md=brief(
            "Airline delay desk",
            "Treat 15+ minute delays as delayed and summarize that set.",
            [
                ("flight_id", "integer", "Flight identifier"),
                ("route", "text", "Origin-destination route"),
                ("delay_min", "integer", "Arrival delay in minutes"),
                ("seats_sold", "integer", "Seats sold"),
            ],
            [
                "Keep flights delayed 15+ minutes",
                "Average delay on that set",
                "Report delay incidence on all flights",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Delayed flights",
                instr(
                    "Isolate delayed flights.",
                    "Keep rows where delay_min is at least 15.",
                    "delayed_flights(df)",
                    "Only 15+ minute delays remain.",
                ),
                "Drop on-time and lightly delayed flights under fifteen minutes.",
                "frame",
                "delayed_flights",
                sig_frame("delayed_flights", "Keep delay_min >= 15."),
                expect_csv=csv_of(delayed),
                xp=25,
            ),
            step(
                "Average delay",
                instr(
                    "How bad were the delayed flights?",
                    "Return mean delay_min on the delayed frame.",
                    "avg_delay(df)",
                    "Average delay to 4 decimals.",
                ),
                "Average minutes among the delayed flights.",
                "metric",
                "avg_delay",
                sig_metric("avg_delay", "Mean delay_min after filter."),
                preprocess="delayed_flights",
                expect=metric4(avg_delay),
                floor=avg_delay - 5,
                xp=25,
            ),
            step(
                "Delay rate",
                instr(
                    "What share of flights delayed?",
                    "On the raw frame, return the fraction with delay_min ≥ 15.",
                    "delay_rate(df)",
                    "Delay rate to 4 decimals.",
                ),
                "Delayed flights as a share of all flights.",
                "metric",
                "delay_rate",
                sig_metric("delay_rate", "Fraction delay_min >= 15."),
                expect=metric4(delay_rate),
                floor=0.3,
                xp=20,
            ),
        ],
    )


def m20_real_estate() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "listing_id": list(range(1, 9)),
            "sqft": [900, 1200, 1500, 1800, 1100, 2000, 1600, 1400],
            "beds": [2, 3, 3, 4, 2, 4, 3, 3],
            "price_k": [220, 310, 360, 450, 260, 520, 390, 340],
        }
    )
    X = raw[["sqft"]]
    y = raw["price_k"]
    corr = float(abs(raw["sqft"].corr(raw["price_k"])))
    model = LinearRegression().fit(X, y)
    r2 = float(model.score(X, y))
    return mission(
        name="Real estate sqft desk",
        slug="real-estate-sqft-desk",
        scenario="A brokerage wants a quick sqft→price sanity check before buying a larger valuation model.",
        track="ml",
        coverkey="house",
        estimateminutes=30,
        brief_md=brief(
            "Real estate sqft desk",
            "Measure sqft–price association, then fit a linear model and report R².",
            [
                ("listing_id", "integer", "Listing identifier"),
                ("sqft", "integer", "Interior square footage"),
                ("beds", "integer", "Bedroom count"),
                ("price_k", "number", "List price in thousands of USD"),
            ],
            [
                "Absolute correlation of sqft vs price_k",
                "Train LinearRegression of price_k on sqft; report training R²",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Correlation check",
                instr(
                    "Confirm sqft tracks price.",
                    "Return the absolute Pearson correlation of sqft and price_k as a float.",
                    "abs_sqft_price_corr(df)",
                    "Absolute correlation to 4 decimals.",
                ),
                "Measure how strongly size and price move together, ignoring sign.",
                "metric",
                "abs_sqft_price_corr",
                sig_metric("abs_sqft_price_corr", "abs(corr(sqft, price_k))."),
                expect=metric4(corr),
                floor=0.9,
                xp=25,
            ),
            step(
                "Linear R²",
                instr(
                    "Fit a simple linear valuation.",
                    "Train LinearRegression predicting price_k from sqft on all rows. Return training R² as a float.",
                    "sqft_price_r2(df)",
                    "Training R² meets expect and floor.",
                ),
                "Fit a straight-line model of price from square footage and report how much variance it explains on the training rows.",
                "metric",
                "sqft_price_r2",
                sig_sklearn(
                    "from sklearn.linear_model import LinearRegression",
                    sig_metric("sqft_price_r2", "Train R² of LinearRegression(sqft→price_k)."),
                ),
                expect=metric4(r2),
                floor=0.9,
                xp=35,
            ),
        ],
    )


def m21_nps() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "resp_id": list(range(1, 11)),
            "score": [9, 10, 7, 6, 8, 3, 9, 4, 10, 5],
            "segment": ["pro", "pro", "free", "free", "pro", "free", "pro", "free", "pro", "free"],
        }
    )
    tagged = raw.copy()

    def label(s: int) -> str:
        if s >= 9:
            return "promoter"
        if s <= 6:
            return "detractor"
        return "passive"

    tagged["nps_class"] = tagged["score"].map(label)
    pro = tagged[tagged["segment"] == "pro"].reset_index(drop=True)
    nps = (float((tagged["nps_class"] == "promoter").mean() - (tagged["nps_class"] == "detractor").mean())) * 100
    return mission(
        name="NPS classification desk",
        slug="nps-classification-desk",
        scenario="Customer success needs classic NPS labels and an overall NPS figure before the QBR.",
        track="wrangling",
        coverkey="clinic",
        estimateminutes=32,
        brief_md=brief(
            "NPS classification desk",
            "Map scores to promoter/passive/detractor and compute NPS = %promoters − %detractors (×100).",
            [
                ("resp_id", "integer", "Response identifier"),
                ("score", "integer", "0–10 likelihood-to-recommend score"),
                ("segment", "text", "Customer segment"),
            ],
            [
                "Add nps_class labels (9–10 promoter, 7–8 passive, ≤6 detractor)",
                "Keep pro segment rows",
                "Report overall NPS on all responses",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Label classes",
                instr(
                    "Classify each score the classic way.",
                    "Add <code>nps_class</code>: promoter if score ≥ 9, detractor if score ≤ 6, else passive.",
                    "add_nps_class(df)",
                    "Labels match the classic bands.",
                ),
                "High scores are promoters, low scores detractors, and the middle band is passive.",
                "frame",
                "add_nps_class",
                sig_frame("add_nps_class", "Add nps_class from score bands."),
                expect_csv=csv_of(tagged),
                xp=30,
            ),
            step(
                "Pro segment",
                instr(
                    "QBR deep-dive is pro customers only.",
                    "Keep segment <code>pro</code>. Grader applies add_nps_class first.",
                    "pro_responses(df)",
                    "Only pro rows remain.",
                ),
                "Filter to the pro segment.",
                "frame",
                "pro_responses",
                sig_frame("pro_responses", "Keep segment==pro."),
                preprocess="add_nps_class",
                expect_csv=csv_of(pro),
                xp=20,
            ),
            step(
                "Overall NPS",
                instr(
                    "Compute classic NPS on all labeled responses.",
                    "On the labeled full frame, return (%promoters − %detractors) × 100 as a float.",
                    "overall_nps(df)",
                    "NPS figure to 4 decimals.",
                ),
                "Subtract the detractor share from the promoter share, then scale to a percentage-point score.",
                "metric",
                "overall_nps",
                sig_metric("overall_nps", "(%promoter - %detractor) * 100."),
                preprocess="add_nps_class",
                expect=metric4(nps),
                floor=nps - 5,
                xp=30,
            ),
        ],
    )


def m22_tickets() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "ticket_id": list(range(1, 9)),
            "priority": ["P1", "P2", "P1", "P3", "P2", "P1", "P3", "P2"],
            "subject": [
                "great support love help",
                "bad delay hate wait",
                "good fix love",
                "terrible bug hate",
                "great response good",
                "bad tone hate",
                "love quick good help",
                "terrible silence hate",
            ],
        }
    )
    POS, NEG = {"good", "great", "love"}, {"bad", "hate", "terrible"}

    def score_text(t: str) -> int:
        toks = t.lower().split()
        return sum(1 for w in toks if w in POS) - sum(1 for w in toks if w in NEG)

    scores = pd.DataFrame({"ticket_id": raw["ticket_id"], "score": [score_text(t) for t in raw["subject"]]})
    vec = CountVectorizer()
    vec.fit(raw["subject"])
    vocab = float(len(vec.vocabulary_))
    return mission(
        name="Support ticket sentiment",
        slug="support-ticket-sentiment",
        scenario="A support lead wants lexicon sentiment on ticket subjects before the on-call handoff.",
        track="nlp",
        coverkey="nlp",
        estimateminutes=28,
        brief_md=brief(
            "Support ticket sentiment",
            "Score subjects with POS={good,great,love} and NEG={bad,hate,terrible}.",
            [
                ("ticket_id", "integer", "Ticket identifier"),
                ("priority", "text", "Priority code"),
                ("subject", "text", "Ticket subject text"),
            ],
            ["Return ticket_id,score", "CountVectorizer vocabulary size on subject"],
        ),
        data=raw,
        steps=[
            step(
                "Lexicon scores",
                instr(
                    "Net POS minus NEG hits per subject.",
                    "Tokenize lowercase on whitespace. Return <code>ticket_id,score</code>.",
                    "lexicon_scores(df)",
                    "Scores match the lexicon.",
                ),
                "Reward positive cue words and penalize negative ones, then net them.",
                "frame",
                "lexicon_scores",
                sig_frame("lexicon_scores", "POS/NEG lexicon; return ticket_id,score."),
                expect_csv=csv_of(scores),
                xp=30,
            ),
            step(
                "Vocabulary size",
                instr(
                    "How wide is the subject vocabulary?",
                    "Fit CountVectorizer on subject; return vocabulary size as a float.",
                    "vocab_size(df)",
                    "Vocabulary size matches.",
                ),
                "Build a bag-of-words vocabulary over all subjects and count unique tokens.",
                "metric",
                "vocab_size",
                sig_sklearn(
                    "from sklearn.feature_extraction.text import CountVectorizer",
                    sig_metric("vocab_size", "CountVectorizer vocabulary size."),
                ),
                expect=metric4(vocab),
                floor=vocab - 1,
                xp=25,
            ),
        ],
    )


def m23_manufacturing() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "batch_id": list(range(1, 9)),
            "line": ["L1", "L1", "L2", "L2", "L1", "L2", "L1", "L2"],
            "defect_rate": [0.01, 0.04, 0.02, 0.08, 0.015, 0.03, 0.05, 0.02],
            "units": [1000, 800, 900, 700, 1100, 950, 850, 1000],
        }
    )
    high = raw[raw["defect_rate"] >= 0.03].reset_index(drop=True)
    by_line = (
        raw.groupby("line", as_index=False)["defect_rate"]
        .mean()
        .rename(columns={"defect_rate": "avg_defect"})
        .sort_values("line")
        .reset_index(drop=True)
    )
    high_share = float((raw["defect_rate"] >= 0.03).mean())
    return mission(
        name="Manufacturing defect desk",
        slug="manufacturing-defect-desk",
        scenario="Plant quality needs high-defect batches before holding a line for inspection.",
        track="eda",
        coverkey="lab",
        estimateminutes=25,
        brief_md=brief(
            "Manufacturing defect desk",
            "Flag batches with defect_rate ≥ 0.03 and compare lines.",
            [
                ("batch_id", "integer", "Batch identifier"),
                ("line", "text", "Production line"),
                ("defect_rate", "number", "Fraction defective"),
                ("units", "integer", "Units in batch"),
            ],
            [
                "Keep high-defect batches",
                "Average defect rate by line",
                "Share of batches that are high-defect",
            ],
        ),
        data=raw,
        steps=[
            step(
                "High defect batches",
                instr(
                    "Hold batches above the defect threshold.",
                    "Keep rows where defect_rate is at least 0.03.",
                    "high_defect_batches(df)",
                    "Only high-defect batches remain.",
                ),
                "Exclude batches below the three-percent defect threshold.",
                "frame",
                "high_defect_batches",
                sig_frame("high_defect_batches", "Keep defect_rate >= 0.03."),
                expect_csv=csv_of(high),
                xp=25,
            ),
            step(
                "Line averages",
                instr(
                    "Compare lines on the full extract.",
                    "Return <code>line,avg_defect</code> sorted by line.",
                    "avg_defect_by_line(df)",
                    "Line averages match.",
                ),
                "Average defect rates within each line.",
                "frame",
                "avg_defect_by_line",
                sig_frame("avg_defect_by_line", "Return line,avg_defect sorted."),
                expect_csv=csv_of(by_line),
                xp=30,
            ),
            step(
                "High-defect share",
                instr(
                    "How common are high-defect batches?",
                    "Return the fraction of batches with defect_rate ≥ 0.03.",
                    "high_defect_share(df)",
                    "Share to 4 decimals.",
                ),
                "High-defect batches as a share of all batches.",
                "metric",
                "high_defect_share",
                sig_metric("high_defect_share", "Fraction defect_rate >= 0.03."),
                expect=metric4(high_share),
                floor=0.3,
                xp=20,
            ),
        ],
    )


def m24_pharmacy() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "rx_id": list(range(1, 9)),
            "drug": ["A", "B", "A", "C", "B", "A", "C", "B"],
            "days_supply": [30, 7, 30, 14, 30, 7, 30, 14],
            "copay": [10, 25, 10, 40, 20, 25, 35, 15],
        }
    )
    month = raw[raw["days_supply"] >= 30].reset_index(drop=True)
    by_drug = (
        month.groupby("drug", as_index=False)["copay"]
        .mean()
        .rename(columns={"copay": "avg_copay"})
        .sort_values("drug")
        .reset_index(drop=True)
    )
    avg = float(month["copay"].mean())
    return mission(
        name="Pharmacy refill desk",
        slug="pharmacy-refill-desk",
        scenario="A pharmacy benefit team is reviewing 30-day fills and average copays by drug.",
        track="wrangling",
        coverkey="clinic",
        estimateminutes=24,
        brief_md=brief(
            "Pharmacy refill desk",
            "Focus on 30-day supplies and copay patterns.",
            [
                ("rx_id", "integer", "Prescription identifier"),
                ("drug", "text", "Drug code"),
                ("days_supply", "integer", "Days supplied"),
                ("copay", "number", "Patient copay in USD"),
            ],
            [
                "Keep 30-day (or longer) fills",
                "Average copay by drug on that set",
                "Overall average copay on that set",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Month fills",
                instr(
                    "Limit to 30-day supplies.",
                    "Keep rows where days_supply is at least 30.",
                    "month_fills(df)",
                    "Only ≥30 day fills remain.",
                ),
                "Exclude shorter supply fills.",
                "frame",
                "month_fills",
                sig_frame("month_fills", "Keep days_supply >= 30."),
                expect_csv=csv_of(month),
                xp=25,
            ),
            step(
                "Copay by drug",
                instr(
                    "Compare drugs on the filtered fills.",
                    "Return <code>drug,avg_copay</code> sorted by drug. Grader applies month_fills first.",
                    "avg_copay_by_drug(df)",
                    "Averages match.",
                ),
                "Average copays within each drug code.",
                "frame",
                "avg_copay_by_drug",
                sig_frame("avg_copay_by_drug", "Return drug,avg_copay sorted."),
                preprocess="month_fills",
                expect_csv=csv_of(by_drug),
                xp=30,
            ),
            step(
                "Overall average copay",
                instr(
                    "One number for the PBM call.",
                    "Return mean copay on the filtered frame.",
                    "avg_month_copay(df)",
                    "Average to 4 decimals.",
                ),
                "Average copays across remaining fills.",
                "metric",
                "avg_month_copay",
                sig_metric("avg_month_copay", "Mean copay after filter."),
                preprocess="month_fills",
                expect=metric4(avg),
                floor=avg - 5,
                xp=20,
            ),
        ],
    )


def m25_museums() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "exhibit_id": list(range(1, 9)),
            "wing": ["north", "north", "south", "south", "north", "south", "north", "south"],
            "comment": [
                "great exhibit love art",
                "bad lighting hate",
                "good curation love",
                "terrible crowd hate",
                "great pieces good",
                "bad signage hate",
                "love gallery good",
                "terrible noise hate",
            ],
        }
    )
    POS, NEG = {"good", "great", "love"}, {"bad", "hate", "terrible"}

    def score_text(t: str) -> int:
        toks = t.lower().split()
        return sum(1 for w in toks if w in POS) - sum(1 for w in toks if w in NEG)

    scores = pd.DataFrame({"exhibit_id": raw["exhibit_id"], "score": [score_text(t) for t in raw["comment"]]})
    pos = scores[scores["score"] > 0].reset_index(drop=True)
    avg = float(scores["score"].mean())
    return mission(
        name="Museum comment sentiment",
        slug="museum-comment-sentiment",
        scenario="A museum curator wants lexicon scores on visitor comments before reallocating gallery space.",
        track="nlp",
        coverkey="nlp",
        estimateminutes=27,
        brief_md=brief(
            "Museum comment sentiment",
            "Score comments with POS={good,great,love} and NEG={bad,hate,terrible}.",
            [
                ("exhibit_id", "integer", "Exhibit identifier"),
                ("wing", "text", "Museum wing"),
                ("comment", "text", "Visitor comment"),
            ],
            ["Return exhibit_id,score", "Keep positive scores", "Average score overall"],
        ),
        data=raw,
        steps=[
            step(
                "Lexicon scores",
                instr(
                    "Net POS minus NEG hits per comment.",
                    "Tokenize lowercase on whitespace. Return <code>exhibit_id,score</code>.",
                    "lexicon_scores(df)",
                    "Scores match the lexicon.",
                ),
                "Reward positive cue words and penalize negative ones, then net them.",
                "frame",
                "lexicon_scores",
                sig_frame("lexicon_scores", "POS/NEG lexicon; return exhibit_id,score."),
                expect_csv=csv_of(scores),
                xp=30,
            ),
            step(
                "Positive comments",
                instr(
                    "Curators want positive-scoring exhibits.",
                    "Keep score > 0. Grader applies lexicon_scores first.",
                    "positive_scores(df)",
                    "Only positive scores remain.",
                ),
                "Keep comments whose net score is above zero.",
                "frame",
                "positive_scores",
                sig_frame("positive_scores", "Keep score > 0."),
                preprocess="lexicon_scores",
                expect_csv=csv_of(pos),
                xp=25,
            ),
            step(
                "Average score",
                instr(
                    "Overall comment sentiment.",
                    "Return mean score on the scored frame.",
                    "avg_score(df)",
                    "Average to 4 decimals.",
                ),
                "Average net scores across all comments.",
                "metric",
                "avg_score",
                sig_metric("avg_score", "Mean score after lexicon_scores."),
                preprocess="lexicon_scores",
                expect=metric4(avg),
                floor=avg - 0.5,
                xp=20,
            ),
        ],
    )


def m26_rideshare() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "ride_id": list(range(1, 9)),
            "city": ["SF", "SF", "LA", "LA", "SF", "LA", "SF", "LA"],
            "fare": [12.5, 28.0, 15.0, 40.0, 9.0, 22.0, 33.0, 18.5],
            "surge": [1.0, 1.5, 1.0, 2.0, 1.0, 1.2, 1.8, 1.0],
        }
    )
    tagged = raw.copy()
    tagged["base_fare"] = tagged["fare"] / tagged["surge"]
    surged = tagged[tagged["surge"] > 1.0].reset_index(drop=True)
    surge_share = float((raw["surge"] > 1.0).mean())
    return mission(
        name="Rideshare surge desk",
        slug="rideshare-surge-desk",
        scenario="A rideshare marketplace ops lead needs base fares and surge incidence before a pricing review.",
        track="wrangling",
        coverkey="sales",
        estimateminutes=28,
        brief_md=brief(
            "Rideshare surge desk",
            "Recover base fare as fare ÷ surge, then inspect surged trips.",
            [
                ("ride_id", "integer", "Ride identifier"),
                ("city", "text", "City code"),
                ("fare", "number", "Amount charged"),
                ("surge", "number", "Surge multiplier (≥1)"),
            ],
            [
                "Add base_fare = fare / surge",
                "Keep trips with surge > 1",
                "Report share of surged trips",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Base fare",
                instr(
                    "Recover the pre-surge amount.",
                    "Add <code>base_fare</code> = fare divided by surge.",
                    "add_base_fare(df)",
                    "base_fare matches fare/surge.",
                ),
                "Undo the surge multiplier to recover the base amount.",
                "frame",
                "add_base_fare",
                sig_frame("add_base_fare", "Add base_fare = fare / surge."),
                expect_csv=csv_of(tagged),
                xp=25,
            ),
            step(
                "Surged trips",
                instr(
                    "List trips that had surge pricing.",
                    "Keep rows where surge is greater than 1. Grader applies add_base_fare first.",
                    "surged_trips(df)",
                    "Only surged trips remain.",
                ),
                "Exclude trips that rode at the base multiplier of one.",
                "frame",
                "surged_trips",
                sig_frame("surged_trips", "Keep surge > 1."),
                preprocess="add_base_fare",
                expect_csv=csv_of(surged),
                xp=25,
            ),
            step(
                "Surge share",
                instr(
                    "How often does surge occur?",
                    "On the raw frame, return the fraction of trips with surge > 1.",
                    "surge_share(df)",
                    "Share to 4 decimals.",
                ),
                "Surged trips as a share of all rides.",
                "metric",
                "surge_share",
                sig_metric("surge_share", "Fraction surge > 1."),
                expect=metric4(surge_share),
                floor=0.4,
                xp=25,
            ),
        ],
    )


def m27_saas() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "account_id": list(range(1, 9)),
            "plan": ["starter", "pro", "starter", "pro", "starter", "pro", "starter", "pro"],
            "mrr": [49, 199, 49, 199, 49, 399, 49, 199],
            "seats": [3, 12, 2, 20, 5, 40, 4, 15],
            "churned": [0, 0, 1, 0, 1, 1, 1, 0],
        }
    )
    X = raw[["mrr", "seats"]]
    y = raw["churned"]
    Xtr, Xte, ytr, yte = train_test_split(X, y, test_size=0.25, random_state=0)
    shapes = pd.DataFrame({"n_train": [len(Xtr)], "n_test": [len(Xte)]})
    clf = LogisticRegression(max_iter=200)
    clf.fit(Xtr, ytr)
    acc = float(clf.score(Xte, yte))
    return mission(
        name="SaaS churn baseline",
        slug="saas-churn-baseline",
        scenario="A SaaS analytics engineer must rebuild a logistic churn baseline from MRR and seats.",
        track="ml",
        coverkey="clinic",
        estimateminutes=42,
        brief_md=brief(
            "SaaS churn baseline",
            "Split accounts, then train logistic churn from MRR and seat count.",
            [
                ("account_id", "integer", "Account identifier"),
                ("plan", "text", "Plan name"),
                ("mrr", "number", "Monthly recurring revenue"),
                ("seats", "integer", "Licensed seats"),
                ("churned", "0 / 1", "1 if churned"),
            ],
            [
                "Report train/test sizes (25% holdout, seed 0)",
                "Logistic test accuracy on mrr+seats → churned",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Split sizes",
                instr(
                    "Document the holdout before modeling.",
                    "Features mrr and seats, target churned, test_size=0.25, random_state=0. Return one-row <code>n_train,n_test</code>.",
                    "split_shapes(df)",
                    "Counts match the fixed split.",
                ),
                "Hold out one quarter of accounts with a fixed seed.",
                "frame",
                "split_shapes",
                sig_sklearn(
                    "from sklearn.model_selection import train_test_split",
                    sig_frame("split_shapes", "n_train,n_test for 0.25 split seed 0."),
                ),
                expect_csv=csv_of(shapes),
                xp=25,
            ),
            step(
                "Logistic accuracy",
                instr(
                    "Train a logistic churn baseline.",
                    "Fit LogisticRegression on the same split; return test accuracy.",
                    "logistic_accuracy(df)",
                    "Accuracy matches expect/floor.",
                ),
                "Train on the training accounts and score accuracy on the held-out accounts.",
                "metric",
                "logistic_accuracy",
                sig_sklearn(
                    "from sklearn.model_selection import train_test_split\nfrom sklearn.linear_model import LogisticRegression",
                    sig_metric("logistic_accuracy", "Test accuracy."),
                ),
                expect=metric4(acc),
                floor=min(0.5, acc),
                xp=40,
            ),
        ],
    )


def m28_libraries() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "item_id": list(range(1, 9)),
            "branch": ["central", "central", "west", "west", "central", "west", "central", "west"],
            "checkouts": [40, 5, 22, 3, 35, 18, 8, 27],
            "days_out": [10, 40, 14, 50, 12, 20, 35, 15],
        }
    )
    overdue = raw[raw["days_out"] > 21].reset_index(drop=True)
    by_br = (
        raw.groupby("branch", as_index=False)["checkouts"]
        .sum()
        .rename(columns={"checkouts": "total_checkouts"})
        .sort_values("branch")
        .reset_index(drop=True)
    )
    overdue_share = float((raw["days_out"] > 21).mean())
    return mission(
        name="Library circulation desk",
        slug="library-circulation-desk",
        scenario="A public library needs overdue items and branch checkout totals before late-notice mailing.",
        track="wrangling",
        coverkey="lab",
        estimateminutes=25,
        brief_md=brief(
            "Library circulation desk",
            "Items out more than 21 days are overdue; also roll up checkouts by branch.",
            [
                ("item_id", "integer", "Item identifier"),
                ("branch", "text", "Branch name"),
                ("checkouts", "integer", "Lifetime checkouts"),
                ("days_out", "integer", "Days currently borrowed"),
            ],
            [
                "List overdue items (days_out > 21)",
                "Sum checkouts by branch",
                "Report overdue share",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Overdue items",
                instr(
                    "Build the late-notice list.",
                    "Keep rows where days_out is greater than 21.",
                    "overdue_items(df)",
                    "Only overdue items remain.",
                ),
                "Exclude items still within the three-week window.",
                "frame",
                "overdue_items",
                sig_frame("overdue_items", "Keep days_out > 21."),
                expect_csv=csv_of(overdue),
                xp=25,
            ),
            step(
                "Branch checkouts",
                instr(
                    "Branch managers want volume.",
                    "Return <code>branch,total_checkouts</code> sorted by branch.",
                    "checkouts_by_branch(df)",
                    "Totals match.",
                ),
                "Add checkouts within each branch.",
                "frame",
                "checkouts_by_branch",
                sig_frame("checkouts_by_branch", "Return branch,total_checkouts sorted."),
                expect_csv=csv_of(by_br),
                xp=30,
            ),
            step(
                "Overdue share",
                instr(
                    "What share of items are overdue?",
                    "Return the fraction with days_out > 21.",
                    "overdue_share(df)",
                    "Share to 4 decimals.",
                ),
                "Overdue items as a share of all items.",
                "metric",
                "overdue_share",
                sig_metric("overdue_share", "Fraction days_out > 21."),
                expect=metric4(overdue_share),
                floor=0.2,
                xp=20,
            ),
        ],
    )


def m29_parking() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "session_id": list(range(1, 9)),
            "lot": ["A", "A", "B", "B", "A", "B", "A", "B"],
            "minutes": [45, 120, 30, 200, 60, 90, 15, 150],
            "paid_usd": [3, 8, 2, 12, 4, 6, 1, 10],
        }
    )
    long = raw[raw["minutes"] >= 60].reset_index(drop=True)
    by_lot = (
        long.groupby("lot", as_index=False)["paid_usd"]
        .sum()
        .rename(columns={"paid_usd": "revenue"})
        .sort_values("lot")
        .reset_index(drop=True)
    )
    avg_min = float(long["minutes"].mean())
    return mission(
        name="Parking session desk",
        slug="parking-session-desk",
        scenario="City parking ops wants revenue on hour-plus sessions before adjusting meter rates.",
        track="wrangling",
        coverkey="lab",
        estimateminutes=24,
        brief_md=brief(
            "Parking session desk",
            "Focus on sessions of 60+ minutes and lot revenue.",
            [
                ("session_id", "integer", "Session identifier"),
                ("lot", "text", "Lot code"),
                ("minutes", "integer", "Minutes parked"),
                ("paid_usd", "number", "Amount paid"),
            ],
            [
                "Keep sessions ≥ 60 minutes",
                "Sum paid_usd by lot on that set",
                "Average minutes on that set",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Long sessions",
                instr(
                    "Ignore short stops for this rate study.",
                    "Keep rows where minutes is at least 60.",
                    "long_sessions(df)",
                    "Only hour-plus sessions remain.",
                ),
                "Drop sessions shorter than one hour.",
                "frame",
                "long_sessions",
                sig_frame("long_sessions", "Keep minutes >= 60."),
                expect_csv=csv_of(long),
                xp=25,
            ),
            step(
                "Lot revenue",
                instr(
                    "Compare lots on long sessions.",
                    "Return <code>lot,revenue</code> sorted by lot. Grader applies long_sessions first.",
                    "lot_revenue(df)",
                    "Revenue totals match.",
                ),
                "Add payments within each lot.",
                "frame",
                "lot_revenue",
                sig_frame("lot_revenue", "Return lot,revenue sorted."),
                preprocess="long_sessions",
                expect_csv=csv_of(by_lot),
                xp=30,
            ),
            step(
                "Average duration",
                instr(
                    "How long do long sessions last on average?",
                    "Return mean minutes on the filtered frame.",
                    "avg_long_minutes(df)",
                    "Average to 4 decimals.",
                ),
                "Average minutes among remaining sessions.",
                "metric",
                "avg_long_minutes",
                sig_metric("avg_long_minutes", "Mean minutes after filter."),
                preprocess="long_sessions",
                expect=metric4(avg_min),
                floor=avg_min - 10,
                xp=20,
            ),
        ],
    )


def m30_coffee() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "order_id": list(range(1, 9)),
            "drink": ["latte", "espresso", "latte", "mocha", "espresso", "latte", "mocha", "espresso"],
            "size": ["M", "S", "L", "M", "S", "L", "L", "S"],
            "price": [4.5, 2.5, 5.5, 5.0, 2.5, 5.5, 6.0, 2.5],
            "peak": [1, 0, 1, 1, 0, 1, 0, 0],
        }
    )
    peak = raw[raw["peak"] == 1].reset_index(drop=True)
    by_drink = (
        peak.groupby("drink", as_index=False)["price"]
        .mean()
        .rename(columns={"price": "avg_price"})
        .sort_values("drink")
        .reset_index(drop=True)
    )
    peak_rev = float(peak["price"].sum())
    return mission(
        name="Coffee shop peak desk",
        slug="coffee-shop-peak-desk",
        scenario="A café manager wants peak-hour drink mix and revenue before staffing the morning rush.",
        track="wrangling",
        coverkey="sales",
        estimateminutes=22,
        brief_md=brief(
            "Coffee shop peak desk",
            "Peak flag marks morning-rush orders; summarize those sales.",
            [
                ("order_id", "integer", "Order identifier"),
                ("drink", "text", "Drink name"),
                ("size", "text", "Cup size"),
                ("price", "number", "Price paid"),
                ("peak", "0 / 1", "1 if ordered in peak window"),
            ],
            [
                "Keep peak orders",
                "Average price by drink on peak orders",
                "Total peak revenue",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Peak orders",
                instr(
                    "Focus on the rush window.",
                    "Keep rows where peak is 1.",
                    "peak_orders(df)",
                    "Only peak orders remain.",
                ),
                "Exclude off-peak tickets.",
                "frame",
                "peak_orders",
                sig_frame("peak_orders", "Keep peak==1."),
                expect_csv=csv_of(peak),
                xp=25,
            ),
            step(
                "Drink averages",
                instr(
                    "What do peak drinks sell for?",
                    "Return <code>drink,avg_price</code> sorted by drink. Grader applies peak_orders first.",
                    "avg_price_by_drink(df)",
                    "Averages match.",
                ),
                "Average prices within each drink name.",
                "frame",
                "avg_price_by_drink",
                sig_frame("avg_price_by_drink", "Return drink,avg_price sorted."),
                preprocess="peak_orders",
                expect_csv=csv_of(by_drink),
                xp=30,
            ),
            step(
                "Peak revenue",
                instr(
                    "How much did the peak window ring?",
                    "Return the sum of price on the peak frame as a float.",
                    "peak_revenue(df)",
                    "Total to 4 decimals.",
                ),
                "Add all peak-order prices together.",
                "metric",
                "peak_revenue",
                sig_metric("peak_revenue", "Sum price after peak filter."),
                preprocess="peak_orders",
                expect=metric4(peak_rev),
                floor=peak_rev - 1,
                xp=20,
            ),
        ],
    )


# Continue with missions 31-50 in the same file...
# To keep the file manageable I'll add the remaining 20 missions below.


def m31_gym() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "member_id": list(range(1, 9)),
            "plan": ["basic", "plus", "basic", "plus", "basic", "plus", "basic", "plus"],
            "visits": [4, 12, 2, 15, 8, 10, 1, 14],
            "canceled": [0, 0, 1, 0, 1, 1, 1, 0],
        }
    )
    X = raw[["visits"]]
    y = raw["canceled"]
    Xtr, Xte, ytr, yte = train_test_split(X, y, test_size=0.25, random_state=0)
    shapes = pd.DataFrame({"n_train": [len(Xtr)], "n_test": [len(Xte)]})
    clf = LogisticRegression()
    clf.fit(Xtr, ytr)
    acc = float(clf.score(Xte, yte))
    return mission(
        name="Gym attendance churn",
        slug="gym-attendance-churn",
        scenario="A fitness chain wants a logistic cancel baseline from visit counts.",
        track="ml",
        coverkey="clinic",
        estimateminutes=38,
        brief_md=brief(
            "Gym attendance churn",
            "Predict membership cancel from monthly visits with an honest split.",
            [
                ("member_id", "integer", "Member identifier"),
                ("plan", "text", "Membership plan"),
                ("visits", "integer", "Visits in the last month"),
                ("canceled", "0 / 1", "1 if membership canceled"),
            ],
            ["Train/test sizes for 25% holdout", "Logistic test accuracy visits→canceled"],
        ),
        data=raw,
        steps=[
            step(
                "Split sizes",
                instr(
                    "Document holdout sizes.",
                    "Feature visits, target canceled, test_size=0.25, random_state=0. Return <code>n_train,n_test</code>.",
                    "split_shapes(df)",
                    "Counts match.",
                ),
                "Hold out one quarter of members with a fixed seed.",
                "frame",
                "split_shapes",
                sig_sklearn(
                    "from sklearn.model_selection import train_test_split",
                    sig_frame("split_shapes", "n_train,n_test."),
                ),
                expect_csv=csv_of(shapes),
                xp=25,
            ),
            step(
                "Logistic accuracy",
                instr(
                    "Fit logistic cancel baseline.",
                    "Train LogisticRegression on the same split; return test accuracy.",
                    "logistic_accuracy(df)",
                    "Accuracy matches expect/floor.",
                ),
                "Train on training members and score the held-out members.",
                "metric",
                "logistic_accuracy",
                sig_sklearn(
                    "from sklearn.model_selection import train_test_split\nfrom sklearn.linear_model import LogisticRegression",
                    sig_metric("logistic_accuracy", "Test accuracy."),
                ),
                expect=metric4(acc),
                floor=min(0.5, acc),
                xp=40,
            ),
        ],
    )


def m32_insurance() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "claim_id": list(range(1, 9)),
            "type": ["auto", "auto", "home", "home", "auto", "home", "auto", "home"],
            "amount": [1200, 8000, 500, 15000, 2200, 900, 4500, 300],
            "fraud_flag": [0, 1, 0, 1, 0, 0, 1, 0],
        }
    )
    big = raw[raw["amount"] >= 2000].reset_index(drop=True)
    by_t = (
        big.groupby("type", as_index=False)["fraud_flag"]
        .mean()
        .rename(columns={"fraud_flag": "fraud_rate"})
        .sort_values("type")
        .reset_index(drop=True)
    )
    rate = float(big["fraud_flag"].mean())
    return mission(
        name="Insurance claim triage",
        slug="insurance-claim-triage",
        scenario="SIU analysts need fraud rates on larger claims before escalating cases.",
        track="eda",
        coverkey="clinic",
        estimateminutes=26,
        brief_md=brief(
            "Insurance claim triage",
            "Review claims of $2000+ and fraud flags by type.",
            [
                ("claim_id", "integer", "Claim identifier"),
                ("type", "text", "Claim type"),
                ("amount", "number", "Claim amount USD"),
                ("fraud_flag", "0 / 1", "1 if flagged for review"),
            ],
            ["Keep large claims", "Fraud rate by type", "Overall fraud rate on large claims"],
        ),
        data=raw,
        steps=[
            step(
                "Large claims",
                instr(
                    "Focus on larger exposures.",
                    "Keep rows where amount is at least 2000.",
                    "large_claims(df)",
                    "Only large claims remain.",
                ),
                "Exclude smaller claims under two thousand.",
                "frame",
                "large_claims",
                sig_frame("large_claims", "Keep amount >= 2000."),
                expect_csv=csv_of(big),
                xp=25,
            ),
            step(
                "Fraud by type",
                instr(
                    "Compare claim types.",
                    "Return <code>type,fraud_rate</code> sorted by type. Grader applies large_claims first.",
                    "fraud_rate_by_type(df)",
                    "Rates match.",
                ),
                "Average fraud flags within each claim type.",
                "frame",
                "fraud_rate_by_type",
                sig_frame("fraud_rate_by_type", "Return type,fraud_rate sorted."),
                preprocess="large_claims",
                expect_csv=csv_of(by_t),
                xp=30,
            ),
            step(
                "Overall fraud rate",
                instr(
                    "One rate for the SIU standup.",
                    "Return mean fraud_flag on the filtered frame.",
                    "large_fraud_rate(df)",
                    "Rate to 4 decimals.",
                ),
                "Average fraud flags across remaining claims.",
                "metric",
                "large_fraud_rate",
                sig_metric("large_fraud_rate", "Mean fraud_flag after filter."),
                preprocess="large_claims",
                expect=metric4(rate),
                floor=0.4,
                xp=20,
            ),
        ],
    )


def m33_restaurants() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "ticket_id": list(range(1, 9)),
            "server": ["Amy", "Ben", "Amy", "Cara", "Ben", "Amy", "Cara", "Ben"],
            "note": [
                "great food love service",
                "bad wait hate",
                "good pasta love",
                "terrible cold hate",
                "great dessert good",
                "bad noise hate",
                "love ambiance good",
                "terrible bill hate",
            ],
        }
    )
    POS, NEG = {"good", "great", "love"}, {"bad", "hate", "terrible"}

    def score_text(t: str) -> int:
        toks = t.lower().split()
        return sum(1 for w in toks if w in POS) - sum(1 for w in toks if w in NEG)

    scores = pd.DataFrame({"ticket_id": raw["ticket_id"], "score": [score_text(t) for t in raw["note"]]})
    vec = CountVectorizer()
    vec.fit(raw["note"])
    vocab = float(len(vec.vocabulary_))
    return mission(
        name="Restaurant note sentiment",
        slug="restaurant-note-sentiment",
        scenario="A restaurant GM wants lexicon scores on guest notes before coaching the floor team.",
        track="nlp",
        coverkey="nlp",
        estimateminutes=26,
        brief_md=brief(
            "Restaurant note sentiment",
            "Score guest notes with POS={good,great,love} and NEG={bad,hate,terrible}.",
            [
                ("ticket_id", "integer", "Ticket identifier"),
                ("server", "text", "Server name"),
                ("note", "text", "Guest comment"),
            ],
            ["Return ticket_id,score", "CountVectorizer vocabulary size on note"],
        ),
        data=raw,
        steps=[
            step(
                "Lexicon scores",
                instr(
                    "Net POS minus NEG hits per note.",
                    "Tokenize lowercase on whitespace. Return <code>ticket_id,score</code>.",
                    "lexicon_scores(df)",
                    "Scores match the lexicon.",
                ),
                "Reward positive cue words and penalize negative ones, then net them.",
                "frame",
                "lexicon_scores",
                sig_frame("lexicon_scores", "POS/NEG lexicon; return ticket_id,score."),
                expect_csv=csv_of(scores),
                xp=30,
            ),
            step(
                "Vocabulary size",
                instr(
                    "How wide is the guest-note vocabulary?",
                    "Fit CountVectorizer on note; return vocabulary size as a float.",
                    "vocab_size(df)",
                    "Vocabulary size matches.",
                ),
                "Build a bag-of-words vocabulary over all notes and count unique tokens.",
                "metric",
                "vocab_size",
                sig_sklearn(
                    "from sklearn.feature_extraction.text import CountVectorizer",
                    sig_metric("vocab_size", "CountVectorizer vocabulary size."),
                ),
                expect=metric4(vocab),
                floor=vocab - 1,
                xp=25,
            ),
        ],
    )


def m34_ecommerce() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "order_id": list(range(1, 9)),
            "device": ["mobile", "desktop", "mobile", "desktop", "mobile", "desktop", "mobile", "desktop"],
            "review": [
                "great checkout love app",
                "bad lag hate",
                "good deal love",
                "terrible shipping hate",
                "great packaging good",
                "bad sizing hate",
                "love speed good",
                "terrible support hate",
            ],
        }
    )
    POS, NEG = {"good", "great", "love"}, {"bad", "hate", "terrible"}

    def score_text(t: str) -> int:
        toks = t.lower().split()
        return sum(1 for w in toks if w in POS) - sum(1 for w in toks if w in NEG)

    scores = pd.DataFrame({"order_id": raw["order_id"], "score": [score_text(t) for t in raw["review"]]})
    pos = scores[scores["score"] > 0].reset_index(drop=True)
    avg = float(scores["score"].mean())
    return mission(
        name="Ecommerce review sentiment",
        slug="ecommerce-review-sentiment",
        scenario="A digital retail team needs lexicon scores on post-purchase reviews before a checkout redesign.",
        track="nlp",
        coverkey="nlp",
        estimateminutes=27,
        brief_md=brief(
            "Ecommerce review sentiment",
            "Score reviews with POS={good,great,love} and NEG={bad,hate,terrible}.",
            [
                ("order_id", "integer", "Order identifier"),
                ("device", "text", "Device class"),
                ("review", "text", "Post-purchase review"),
            ],
            ["Return order_id,score", "Keep positive scores", "Average score overall"],
        ),
        data=raw,
        steps=[
            step(
                "Lexicon scores",
                instr(
                    "Net POS minus NEG hits per review.",
                    "Tokenize lowercase on whitespace. Return <code>order_id,score</code>.",
                    "lexicon_scores(df)",
                    "Scores match the lexicon.",
                ),
                "Reward positive cue words and penalize negative ones, then net them.",
                "frame",
                "lexicon_scores",
                sig_frame("lexicon_scores", "POS/NEG lexicon; return order_id,score."),
                expect_csv=csv_of(scores),
                xp=30,
            ),
            step(
                "Positive reviews",
                instr(
                    "Product wants positive-scoring orders.",
                    "Keep score > 0. Grader applies lexicon_scores first.",
                    "positive_scores(df)",
                    "Only positive scores remain.",
                ),
                "Keep reviews whose net score is above zero.",
                "frame",
                "positive_scores",
                sig_frame("positive_scores", "Keep score > 0."),
                preprocess="lexicon_scores",
                expect_csv=csv_of(pos),
                xp=25,
            ),
            step(
                "Average score",
                instr(
                    "Overall review sentiment.",
                    "Return mean score on the scored frame.",
                    "avg_score(df)",
                    "Average to 4 decimals.",
                ),
                "Average net scores across all reviews.",
                "metric",
                "avg_score",
                sig_metric("avg_score", "Mean score after lexicon_scores."),
                preprocess="lexicon_scores",
                expect=metric4(avg),
                floor=avg - 0.5,
                xp=20,
            ),
        ],
    )


def m35_freight() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "load_id": list(range(1, 9)),
            "miles": [100, 250, 80, 400, 150, 300, 120, 220],
            "rate_usd": [200, 600, 150, 1000, 320, 700, 240, 500],
        }
    )
    tagged = raw.copy()
    tagged["rpm"] = tagged["rate_usd"] / tagged["miles"]
    longhaul = tagged[tagged["miles"] >= 200].reset_index(drop=True)
    avg_rpm = float(longhaul["rpm"].mean())
    return mission(
        name="Freight rate-per-mile",
        slug="freight-rate-per-mile",
        scenario="A broker desk needs rate-per-mile on longer loads before quoting shippers.",
        track="wrangling",
        coverkey="ship",
        estimateminutes=23,
        brief_md=brief(
            "Freight rate-per-mile",
            "Compute RPM and focus on loads of 200+ miles.",
            [
                ("load_id", "integer", "Load identifier"),
                ("miles", "number", "Loaded miles"),
                ("rate_usd", "number", "Total rate"),
            ],
            ["Add rpm = rate/miles", "Keep long-haul loads", "Average RPM on long-haul"],
        ),
        data=raw,
        steps=[
            step(
                "Add RPM",
                instr(
                    "Normalize rates by distance.",
                    "Add <code>rpm</code> = rate_usd / miles.",
                    "add_rpm(df)",
                    "rpm matches the ratio.",
                ),
                "Divide total rate by miles for each load.",
                "frame",
                "add_rpm",
                sig_frame("add_rpm", "Add rpm = rate_usd/miles."),
                expect_csv=csv_of(tagged),
                xp=25,
            ),
            step(
                "Long haul",
                instr(
                    "Quote review is 200+ miles.",
                    "Keep miles ≥ 200. Grader applies add_rpm first.",
                    "long_haul(df)",
                    "Only long-haul loads remain.",
                ),
                "Exclude shorter loads under two hundred miles.",
                "frame",
                "long_haul",
                sig_frame("long_haul", "Keep miles >= 200."),
                preprocess="add_rpm",
                expect_csv=csv_of(longhaul),
                xp=25,
            ),
            step(
                "Average RPM",
                instr(
                    "One RPM for the quote sheet.",
                    "Return mean rpm on the long-haul frame.",
                    "avg_longhaul_rpm(df)",
                    "Average to 4 decimals.",
                ),
                "Average RPM among remaining long-haul loads.",
                "metric",
                "avg_longhaul_rpm",
                sig_metric("avg_longhaul_rpm", "Mean rpm after filter."),
                preprocess="long_haul",
                expect=metric4(avg_rpm),
                floor=avg_rpm - 0.2,
                xp=25,
            ),
        ],
    )


def m36_cinema() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "film_id": list(range(1, 9)),
            "genre": ["action", "drama", "action", "comedy", "drama", "comedy", "action", "drama"],
            "review": [
                "good film love it",
                "bad movie hate it",
                "great show love",
                "terrible film",
                "good drama",
                "bad comedy hate",
                "great action love",
                "terrible hate movie",
            ],
        }
    )
    POS = {"good", "great", "love"}
    NEG = {"bad", "hate", "terrible"}

    def score_text(t: str) -> int:
        toks = t.lower().split()
        return sum(1 for w in toks if w in POS) - sum(1 for w in toks if w in NEG)

    scores = pd.DataFrame({"film_id": raw["film_id"], "score": [score_text(t) for t in raw["review"]]})
    vec = CountVectorizer()
    vec.fit(raw["review"])
    vocab = float(len(vec.vocabulary_))
    return mission(
        name="Cinema review sentiment",
        slug="cinema-review-sentiment",
        scenario="Content ops wants a cheap lexicon score before wiring a heavier NLP model for showtimes.",
        track="nlp",
        coverkey="nlp",
        estimateminutes=30,
        brief_md=brief(
            "Cinema review sentiment",
            "Score short reviews with a tiny lexicon, then measure bag-of-words vocabulary size.",
            [
                ("film_id", "integer", "Film identifier"),
                ("genre", "text", "Genre label"),
                ("review", "text", "Short review text"),
            ],
            [
                "Lexicon score: +1 per POS word, −1 per NEG word; return film_id,score",
                "Fit CountVectorizer on review; return vocabulary size",
            ],
        ),
        data=raw,
        steps=[
            step(
                "Lexicon scores",
                instr(
                    "Score each review with the POS/NEG word lists in the signature docstring context: POS={good,great,love}, NEG={bad,hate,terrible}.",
                    "Tokenize on whitespace (lowercase). Return a frame <code>film_id,score</code> with the net score.",
                    "lexicon_scores(df)",
                    "Scores match the lexicon rule.",
                ),
                "Reward positive cue words and penalize negative cue words, then net them per review.",
                "frame",
                "lexicon_scores",
                sig_frame(
                    "lexicon_scores",
                    "POS={good,great,love} NEG={bad,hate,terrible}; return film_id,score.",
                ),
                expect_csv=csv_of(scores),
                xp=30,
            ),
            step(
                "Vocabulary size",
                instr(
                    "Measure how wide the review vocabulary is.",
                    "Fit CountVectorizer on the review column; return vocabulary size as a float.",
                    "vocab_size(df)",
                    "Vocabulary size matches.",
                ),
                "Build a bag-of-words vocabulary over all reviews and count unique tokens.",
                "metric",
                "vocab_size",
                sig_sklearn(
                    "from sklearn.feature_extraction.text import CountVectorizer",
                    sig_metric("vocab_size", "CountVectorizer vocabulary size."),
                ),
                expect=metric4(vocab),
                floor=vocab - 1,
                xp=25,
            ),
        ],
    )


def m37_utilities() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "account_id": list(range(1, 9)),
            "region": ["north", "north", "south", "south", "north", "south", "north", "south"],
            "kwh": [300, 450, 280, 600, 320, 500, 410, 290],
            "bill": [45, 70, 40, 95, 50, 80, 65, 42],
        }
    )
    corr = float(abs(raw["kwh"].corr(raw["bill"])))
    model = LinearRegression().fit(raw[["kwh"]], raw["bill"])
    r2 = float(model.score(raw[["kwh"]], raw["bill"]))
    return mission(
        name="Utilities bill model",
        slug="utilities-bill-model",
        scenario="A utility analyst needs a quick kWh→bill linear check before publishing a rate explainer.",
        track="ml",
        coverkey="house",
        estimateminutes=28,
        brief_md=brief(
            "Utilities bill model",
            "Correlate usage and bill, then fit a linear model.",
            [
                ("account_id", "integer", "Account identifier"),
                ("region", "text", "Service region"),
                ("kwh", "number", "Usage in kWh"),
                ("bill", "number", "Bill amount USD"),
            ],
            ["Absolute correlation kwh vs bill", "LinearRegression R² of bill on kwh"],
        ),
        data=raw,
        steps=[
            step(
                "Usage–bill link",
                instr(
                    "Confirm usage tracks the bill.",
                    "Return absolute Pearson correlation of kwh and bill.",
                    "abs_kwh_bill_corr(df)",
                    "Correlation to 4 decimals.",
                ),
                "Measure association strength between usage and bill, ignoring sign.",
                "metric",
                "abs_kwh_bill_corr",
                sig_metric("abs_kwh_bill_corr", "abs(corr(kwh,bill))."),
                expect=metric4(corr),
                floor=0.9,
                xp=25,
            ),
            step(
                "Linear R²",
                instr(
                    "Fit a simple linear explainer.",
                    "Train LinearRegression predicting bill from kwh; return training R².",
                    "kwh_bill_r2(df)",
                    "R² matches expect/floor.",
                ),
                "Fit a straight-line model of bill from usage and report explained variance on the training rows.",
                "metric",
                "kwh_bill_r2",
                sig_sklearn(
                    "from sklearn.linear_model import LinearRegression",
                    sig_metric("kwh_bill_r2", "Train R²."),
                ),
                expect=metric4(r2),
                floor=0.9,
                xp=35,
            ),
        ],
    )


def m38_campus() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "event_id": list(range(1, 9)),
            "org": ["robotics", "debate", "robotics", "music", "debate", "music", "robotics", "debate"],
            "attendees": [40, 15, 55, 30, 12, 45, 20, 18],
            "budget": [200, 80, 250, 150, 60, 180, 100, 90],
        }
    )
    big = raw[raw["attendees"] >= 20].reset_index(drop=True)
    by_org = (
        big.groupby("org", as_index=False)["budget"]
        .sum()
        .rename(columns={"budget": "budget_total"})
        .sort_values("org")
        .reset_index(drop=True)
    )
    avg_att = float(big["attendees"].mean())
    return mission(
        name="Campus events desk",
        slug="campus-events-desk",
        scenario="Student affairs wants budget totals for larger events before approving next term's calendar.",
        track="wrangling",
        coverkey="lab",
        estimateminutes=24,
        brief_md=brief(
            "Campus events desk",
            "Focus on events with 20+ attendees.",
            [
                ("event_id", "integer", "Event identifier"),
                ("org", "text", "Student organization"),
                ("attendees", "integer", "Headcount"),
                ("budget", "number", "Budget USD"),
            ],
            ["Keep larger events", "Sum budget by org", "Average attendance on that set"],
        ),
        data=raw,
        steps=[
            step(
                "Larger events",
                instr(
                    "Ignore tiny meetups.",
                    "Keep attendees ≥ 20.",
                    "large_events(df)",
                    "Only larger events remain.",
                ),
                "Drop events under twenty attendees.",
                "frame",
                "large_events",
                sig_frame("large_events", "Keep attendees >= 20."),
                expect_csv=csv_of(big),
                xp=25,
            ),
            step(
                "Org budgets",
                instr(
                    "Roll budget by organization.",
                    "Return <code>org,budget_total</code> sorted by org. Grader applies large_events first.",
                    "budget_by_org(df)",
                    "Totals match.",
                ),
                "Add budgets within each organization.",
                "frame",
                "budget_by_org",
                sig_frame("budget_by_org", "Return org,budget_total sorted."),
                preprocess="large_events",
                expect_csv=csv_of(by_org),
                xp=30,
            ),
            step(
                "Average attendance",
                instr(
                    "Typical headcount for approved events.",
                    "Return mean attendees on the filtered frame.",
                    "avg_attendees(df)",
                    "Average to 4 decimals.",
                ),
                "Average attendance among remaining events.",
                "metric",
                "avg_attendees",
                sig_metric("avg_attendees", "Mean attendees after filter."),
                preprocess="large_events",
                expect=metric4(avg_att),
                floor=avg_att - 5,
                xp=20,
            ),
        ],
    )


def m39_fleet() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "vehicle_id": list(range(1, 9)),
            "depot": ["north", "north", "south", "south", "north", "south", "north", "south"],
            "miles": [1200, 800, 1500, 600, 2000, 900, 700, 1800],
            "maint_cost": [200, 120, 350, 90, 500, 150, 100, 400],
        }
    )
    tagged = raw.copy()
    tagged["cost_per_mile"] = tagged["maint_cost"] / tagged["miles"]
    heavy = tagged[tagged["miles"] >= 1000].reset_index(drop=True)
    avg = float(heavy["cost_per_mile"].mean())
    return mission(
        name="Fleet maintenance desk",
        slug="fleet-maintenance-desk",
        scenario="Fleet ops needs maintenance cost-per-mile on high-mileage vehicles.",
        track="wrangling",
        coverkey="ship",
        estimateminutes=25,
        brief_md=brief(
            "Fleet maintenance desk",
            "Normalize maintenance cost by miles, then focus on 1000+ mile vehicles.",
            [
                ("vehicle_id", "integer", "Vehicle identifier"),
                ("depot", "text", "Depot name"),
                ("miles", "number", "Miles in period"),
                ("maint_cost", "number", "Maintenance spend"),
            ],
            ["Add cost_per_mile", "Keep high-mileage vehicles", "Average cost_per_mile on that set"],
        ),
        data=raw,
        steps=[
            step(
                "Cost per mile",
                instr(
                    "Normalize maintenance spend.",
                    "Add <code>cost_per_mile</code> = maint_cost / miles.",
                    "add_cpm(df)",
                    "cost_per_mile matches.",
                ),
                "Divide maintenance spend by miles for each vehicle.",
                "frame",
                "add_cpm",
                sig_frame("add_cpm", "Add cost_per_mile."),
                expect_csv=csv_of(tagged),
                xp=25,
            ),
            step(
                "High mileage",
                instr(
                    "Focus on hard-working vehicles.",
                    "Keep miles ≥ 1000. Grader applies add_cpm first.",
                    "high_mileage(df)",
                    "Only high-mileage vehicles remain.",
                ),
                "Exclude lower-mileage vehicles under one thousand miles.",
                "frame",
                "high_mileage",
                sig_frame("high_mileage", "Keep miles >= 1000."),
                preprocess="add_cpm",
                expect_csv=csv_of(heavy),
                xp=25,
            ),
            step(
                "Average CPM",
                instr(
                    "One cost-per-mile for the depot review.",
                    "Return mean cost_per_mile on the filtered frame.",
                    "avg_cpm(df)",
                    "Average to 4 decimals.",
                ),
                "Average cost-per-mile among remaining vehicles.",
                "metric",
                "avg_cpm",
                sig_metric("avg_cpm", "Mean cost_per_mile after filter."),
                preprocess="high_mileage",
                expect=metric4(avg),
                floor=avg - 0.05,
                xp=25,
            ),
        ],
    )


def m40_wellness() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "user_id": list(range(1, 9)),
            "steps": [3000, 8000, 5000, 12000, 2000, 9000, 7000, 4000],
            "sleep_h": [5.0, 7.5, 6.0, 8.0, 4.5, 7.0, 6.5, 5.5],
            "mood": [2, 4, 3, 5, 1, 4, 3, 2],
        }
    )
    active = raw[raw["steps"] >= 6000].reset_index(drop=True)
    corr = float(abs(raw["sleep_h"].corr(raw["mood"])))
    return mission(
        name="Wellness sleep desk",
        slug="wellness-sleep-desk",
        scenario="A wellness app team wants active-user slices and sleep–mood association for a product brief.",
        track="eda",
        coverkey="eda",
        estimateminutes=22,
        brief_md=brief(
            "Wellness sleep desk",
            "Active users took 6000+ steps; also measure sleep vs mood correlation.",
            [
                ("user_id", "integer", "User identifier"),
                ("steps", "integer", "Daily steps"),
                ("sleep_h", "number", "Hours of sleep"),
                ("mood", "integer", "Self-reported mood 1–5"),
            ],
            ["Keep active users", "Absolute correlation of sleep_h and mood"],
        ),
        data=raw,
        steps=[
            step(
                "Active users",
                instr(
                    "Focus on more active days.",
                    "Keep steps ≥ 6000.",
                    "active_users(df)",
                    "Only active users remain.",
                ),
                "Exclude lower step counts under six thousand.",
                "frame",
                "active_users",
                sig_frame("active_users", "Keep steps >= 6000."),
                expect_csv=csv_of(active),
                xp=25,
            ),
            step(
                "Sleep–mood link",
                instr(
                    "How do sleep and mood move together?",
                    "Return absolute Pearson correlation of sleep_h and mood on the full extract.",
                    "abs_sleep_mood_corr(df)",
                    "Correlation to 4 decimals.",
                ),
                "Measure association strength between sleep and mood, ignoring sign.",
                "metric",
                "abs_sleep_mood_corr",
                sig_metric("abs_sleep_mood_corr", "abs(corr(sleep_h, mood))."),
                expect=metric4(corr),
                floor=max(0.5, corr - 0.3),
                xp=30,
            ),
        ],
    )


def m41_fundraising() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "donor_id": list(range(1, 9)),
            "channel": ["email", "event", "email", "event", "email", "event", "email", "event"],
            "gift": [50, 500, 25, 1000, 75, 250, 40, 800],
            "recurring": [1, 0, 1, 0, 0, 0, 1, 0],
        }
    )
    major = raw[raw["gift"] >= 250].reset_index(drop=True)
    by_ch = (
        raw.groupby("channel", as_index=False)["gift"]
        .sum()
        .rename(columns={"gift": "total_gift"})
        .sort_values("channel")
        .reset_index(drop=True)
    )
    rec_rate = float(raw["recurring"].mean())
    return mission(
        name="Fundraising gift desk",
        slug="fundraising-gift-desk",
        scenario="Advancement ops needs major-gift lists and channel totals before a board report.",
        track="wrangling",
        coverkey="sales",
        estimateminutes=24,
        brief_md=brief(
            "Fundraising gift desk",
            "Major gifts are $250+; also roll gifts by channel.",
            [
                ("donor_id", "integer", "Donor identifier"),
                ("channel", "text", "Gift channel"),
                ("gift", "number", "Gift amount USD"),
                ("recurring", "0 / 1", "1 if recurring donor"),
            ],
            ["Keep major gifts", "Sum gifts by channel", "Recurring donor rate"],
        ),
        data=raw,
        steps=[
            step(
                "Major gifts",
                instr(
                    "Pull major-gift prospects.",
                    "Keep gift ≥ 250.",
                    "major_gifts(df)",
                    "Only major gifts remain.",
                ),
                "Exclude smaller gifts under two hundred fifty.",
                "frame",
                "major_gifts",
                sig_frame("major_gifts", "Keep gift >= 250."),
                expect_csv=csv_of(major),
                xp=25,
            ),
            step(
                "Channel totals",
                instr(
                    "Compare channels.",
                    "Return <code>channel,total_gift</code> sorted by channel.",
                    "gifts_by_channel(df)",
                    "Totals match.",
                ),
                "Add gift amounts within each channel.",
                "frame",
                "gifts_by_channel",
                sig_frame("gifts_by_channel", "Return channel,total_gift sorted."),
                expect_csv=csv_of(by_ch),
                xp=30,
            ),
            step(
                "Recurring rate",
                instr(
                    "What share of donors are recurring?",
                    "Return mean recurring as a float.",
                    "recurring_rate(df)",
                    "Rate to 4 decimals.",
                ),
                "Average the recurring flags.",
                "metric",
                "recurring_rate",
                sig_metric("recurring_rate", "Mean recurring."),
                expect=metric4(rec_rate),
                floor=0.3,
                xp=20,
            ),
        ],
    )


def m42_callcenter() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "call_id": list(range(1, 9)),
            "queue": ["billing", "tech", "billing", "tech", "billing", "tech", "billing", "tech"],
            "handle_min": [8, 15, 6, 20, 12, 9, 5, 18],
            "csat": [4, 3, 5, 2, 4, 3, 5, 2],
        }
    )
    long = raw[raw["handle_min"] >= 10].reset_index(drop=True)
    by_q = (
        raw.groupby("queue", as_index=False)["csat"]
        .mean()
        .rename(columns={"csat": "avg_csat"})
        .sort_values("queue")
        .reset_index(drop=True)
    )
    avg = float(long["csat"].mean())
    return mission(
        name="Call center CSAT desk",
        slug="call-center-csat-desk",
        scenario="A contact center coach needs CSAT on longer calls before the weekly calibration.",
        track="eda",
        coverkey="clinic",
        estimateminutes=25,
        brief_md=brief(
            "Call center CSAT desk",
            "Focus on calls of 10+ minutes and queue CSAT.",
            [
                ("call_id", "integer", "Call identifier"),
                ("queue", "text", "Queue name"),
                ("handle_min", "integer", "Handle time minutes"),
                ("csat", "integer", "Customer satisfaction 1–5"),
            ],
            ["Keep longer calls", "Average CSAT by queue", "Average CSAT on longer calls"],
        ),
        data=raw,
        steps=[
            step(
                "Longer calls",
                instr(
                    "Calibration focuses on longer handle times.",
                    "Keep handle_min ≥ 10.",
                    "long_calls(df)",
                    "Only longer calls remain.",
                ),
                "Drop shorter calls under ten minutes.",
                "frame",
                "long_calls",
                sig_frame("long_calls", "Keep handle_min >= 10."),
                expect_csv=csv_of(long),
                xp=25,
            ),
            step(
                "Queue CSAT",
                instr(
                    "Compare queues on the full extract.",
                    "Return <code>queue,avg_csat</code> sorted by queue.",
                    "avg_csat_by_queue(df)",
                    "Averages match.",
                ),
                "Average CSAT within each queue.",
                "frame",
                "avg_csat_by_queue",
                sig_frame("avg_csat_by_queue", "Return queue,avg_csat sorted."),
                expect_csv=csv_of(by_q),
                xp=30,
            ),
            step(
                "Long-call CSAT",
                instr(
                    "Average CSAT on the longer-call set.",
                    "Return mean csat on the filtered frame.",
                    "avg_long_csat(df)",
                    "Average to 4 decimals.",
                ),
                "Average CSAT among remaining longer calls.",
                "metric",
                "avg_long_csat",
                sig_metric("avg_long_csat", "Mean csat after filter."),
                preprocess="long_calls",
                expect=metric4(avg),
                floor=avg - 0.5,
                xp=20,
            ),
        ],
    )


def m43_grocery() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "sku": ["milk", "bread", "eggs", "apples", "chips", "soda", "yogurt", "cereal"],
            "aisle": ["dairy", "bakery", "dairy", "produce", "snack", "snack", "dairy", "bakery"],
            "units": [40, 25, 30, 50, 15, 20, 18, 12],
            "waste": [2, 1, 0, 5, 3, 1, 2, 0],
        }
    )
    tagged = raw.copy()
    tagged["waste_rate"] = tagged["waste"] / tagged["units"]
    dairy = tagged[tagged["aisle"] == "dairy"].reset_index(drop=True)
    avg = float(dairy["waste_rate"].mean())
    return mission(
        name="Grocery waste desk",
        slug="grocery-waste-desk",
        scenario="A grocery ops lead needs waste rates by aisle before adjusting order quantities.",
        track="wrangling",
        coverkey="sales",
        estimateminutes=24,
        brief_md=brief(
            "Grocery waste desk",
            "Compute waste rate and review dairy aisle.",
            [
                ("sku", "text", "Product name"),
                ("aisle", "text", "Aisle category"),
                ("units", "integer", "Units received"),
                ("waste", "integer", "Units wasted"),
            ],
            ["Add waste_rate", "Keep dairy aisle", "Average dairy waste rate"],
        ),
        data=raw,
        steps=[
            step(
                "Waste rate",
                instr(
                    "Normalize waste to units received.",
                    "Add <code>waste_rate</code> = waste / units.",
                    "add_waste_rate(df)",
                    "waste_rate matches.",
                ),
                "Divide wasted units by units received.",
                "frame",
                "add_waste_rate",
                sig_frame("add_waste_rate", "Add waste_rate = waste/units."),
                expect_csv=csv_of(tagged),
                xp=25,
            ),
            step(
                "Dairy aisle",
                instr(
                    "Today's review is dairy.",
                    "Keep aisle <code>dairy</code>. Grader applies add_waste_rate first.",
                    "dairy_skus(df)",
                    "Only dairy rows remain.",
                ),
                "Filter to the dairy aisle.",
                "frame",
                "dairy_skus",
                sig_frame("dairy_skus", "Keep aisle==dairy."),
                preprocess="add_waste_rate",
                expect_csv=csv_of(dairy),
                xp=25,
            ),
            step(
                "Dairy average waste",
                instr(
                    "Average dairy waste rate.",
                    "Return mean waste_rate on the dairy frame.",
                    "avg_dairy_waste(df)",
                    "Average to 4 decimals.",
                ),
                "Average waste rates among remaining dairy SKUs.",
                "metric",
                "avg_dairy_waste",
                sig_metric("avg_dairy_waste", "Mean waste_rate for dairy."),
                preprocess="dairy_skus",
                expect=metric4(avg),
                floor=avg - 0.05,
                xp=25,
            ),
        ],
    )


def m44_festivals() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "act_id": list(range(1, 9)),
            "stage": ["main", "main", "side", "side", "main", "side", "main", "side"],
            "attendees": [5000, 4200, 800, 600, 4800, 900, 3500, 700],
            "score": [4.8, 4.5, 4.0, 3.5, 4.7, 3.8, 4.2, 4.1],
        }
    )
    main = raw[raw["stage"] == "main"].reset_index(drop=True)
    by_s = (
        raw.groupby("stage", as_index=False)["score"]
        .mean()
        .rename(columns={"score": "avg_score"})
        .sort_values("stage")
        .reset_index(drop=True)
    )
    avg = float(main["attendees"].mean())
    return mission(
        name="Festival stage desk",
        slug="festival-stage-desk",
        scenario="Festival production wants main-stage attendance and score by stage before booking next year.",
        track="eda",
        coverkey="eda",
        estimateminutes=22,
        brief_md=brief(
            "Festival stage desk",
            "Compare main vs side stages on crowd and scores.",
            [
                ("act_id", "integer", "Act identifier"),
                ("stage", "text", "Stage name"),
                ("attendees", "integer", "Crowd size"),
                ("score", "number", "Audience score"),
            ],
            ["Keep main stage acts", "Average score by stage", "Average main-stage attendance"],
        ),
        data=raw,
        steps=[
            step(
                "Main stage",
                instr(
                    "Headliners are main stage.",
                    "Keep stage <code>main</code>.",
                    "main_stage(df)",
                    "Only main stage remains.",
                ),
                "Exclude side-stage acts.",
                "frame",
                "main_stage",
                sig_frame("main_stage", "Keep stage==main."),
                expect_csv=csv_of(main),
                xp=25,
            ),
            step(
                "Scores by stage",
                instr(
                    "Compare stages.",
                    "Return <code>stage,avg_score</code> sorted by stage.",
                    "avg_score_by_stage(df)",
                    "Averages match.",
                ),
                "Average audience scores within each stage.",
                "frame",
                "avg_score_by_stage",
                sig_frame("avg_score_by_stage", "Return stage,avg_score sorted."),
                expect_csv=csv_of(by_s),
                xp=30,
            ),
            step(
                "Main attendance",
                instr(
                    "Average main-stage crowd.",
                    "Return mean attendees on the main-stage frame.",
                    "avg_main_attendees(df)",
                    "Average to 4 decimals.",
                ),
                "Average attendance among main-stage acts.",
                "metric",
                "avg_main_attendees",
                sig_metric("avg_main_attendees", "Mean attendees for main."),
                preprocess="main_stage",
                expect=metric4(avg),
                floor=avg - 200,
                xp=20,
            ),
        ],
    )


def m45_coworking() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "desk_id": list(range(1, 9)),
            "floor": ["2", "2", "3", "3", "2", "3", "2", "3"],
            "hours_booked": [20, 5, 30, 8, 25, 12, 4, 28],
            "member_type": ["hot", "hot", "dedicated", "hot", "dedicated", "hot", "hot", "dedicated"],
        }
    )
    busy = raw[raw["hours_booked"] >= 15].reset_index(drop=True)
    by_f = (
        busy.groupby("floor", as_index=False)["hours_booked"]
        .sum()
        .rename(columns={"hours_booked": "total_hours"})
        .sort_values("floor")
        .reset_index(drop=True)
    )
    share = float((raw["member_type"] == "dedicated").mean())
    return mission(
        name="Coworking desk utilization",
        slug="coworking-desk-utilization",
        scenario="A coworking community manager needs busy-desk utilization before expanding floor 3.",
        track="wrangling",
        coverkey="house",
        estimateminutes=24,
        brief_md=brief(
            "Coworking desk utilization",
            "Busy desks have 15+ booked hours; roll hours by floor.",
            [
                ("desk_id", "integer", "Desk identifier"),
                ("floor", "text", "Floor number"),
                ("hours_booked", "number", "Hours booked in period"),
                ("member_type", "text", "`hot` or `dedicated`"),
            ],
            ["Keep busy desks", "Sum hours by floor on that set", "Dedicated member share overall"],
        ),
        data=raw,
        steps=[
            step(
                "Busy desks",
                instr(
                    "Ignore lightly used desks.",
                    "Keep hours_booked ≥ 15.",
                    "busy_desks(df)",
                    "Only busy desks remain.",
                ),
                "Drop desks under fifteen booked hours.",
                "frame",
                "busy_desks",
                sig_frame("busy_desks", "Keep hours_booked >= 15."),
                expect_csv=csv_of(busy),
                xp=25,
            ),
            step(
                "Floor hours",
                instr(
                    "Compare floors on busy desks.",
                    "Return <code>floor,total_hours</code> sorted by floor. Grader applies busy_desks first.",
                    "hours_by_floor(df)",
                    "Totals match.",
                ),
                "Add booked hours within each floor.",
                "frame",
                "hours_by_floor",
                sig_frame("hours_by_floor", "Return floor,total_hours sorted."),
                preprocess="busy_desks",
                expect_csv=csv_of(by_f),
                xp=30,
            ),
            step(
                "Dedicated share",
                instr(
                    "What share of desks are dedicated?",
                    "Return fraction with member_type <code>dedicated</code> on the raw frame.",
                    "dedicated_share(df)",
                    "Share to 4 decimals.",
                ),
                "Dedicated desks as a share of all desks.",
                "metric",
                "dedicated_share",
                sig_metric("dedicated_share", "Fraction member_type==dedicated."),
                expect=metric4(share),
                floor=0.3,
                xp=20,
            ),
        ],
    )


def m46_veterinary() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "visit_id": list(range(1, 9)),
            "species": ["dog", "cat", "dog", "cat", "dog", "cat", "dog", "cat"],
            "weight_kg": [12, 4, 25, 5, 8, 3, 30, 6],
            "cost": [80, 60, 150, 70, 90, 55, 200, 65],
        }
    )
    dogs = raw[raw["species"] == "dog"].reset_index(drop=True)
    model = LinearRegression().fit(dogs[["weight_kg"]], dogs["cost"])
    r2 = float(model.score(dogs[["weight_kg"]], dogs["cost"]))
    corr = float(abs(dogs["weight_kg"].corr(dogs["cost"])))
    return mission(
        name="Veterinary visit model",
        slug="veterinary-visit-model",
        scenario="A clinic manager wants a weight→cost linear sanity check for dog visits.",
        track="ml",
        coverkey="clinic",
        estimateminutes=32,
        brief_md=brief(
            "Veterinary visit model",
            "Focus on dog visits; correlate weight and cost, then fit a linear model.",
            [
                ("visit_id", "integer", "Visit identifier"),
                ("species", "text", "Animal species"),
                ("weight_kg", "number", "Patient weight"),
                ("cost", "number", "Visit cost USD"),
            ],
            ["Keep dog visits", "Absolute corr weight vs cost", "Linear R² of cost on weight"],
        ),
        data=raw,
        steps=[
            step(
                "Dog visits",
                instr(
                    "This model is dogs-only.",
                    "Keep species <code>dog</code>.",
                    "dog_visits(df)",
                    "Only dog visits remain.",
                ),
                "Exclude other species.",
                "frame",
                "dog_visits",
                sig_frame("dog_visits", "Keep species==dog."),
                expect_csv=csv_of(dogs),
                xp=20,
            ),
            step(
                "Weight–cost link",
                instr(
                    "Confirm weight tracks cost.",
                    "Return absolute correlation of weight_kg and cost. Grader applies dog_visits first.",
                    "abs_weight_cost_corr(df)",
                    "Correlation to 4 decimals.",
                ),
                "Measure association strength between weight and cost, ignoring sign.",
                "metric",
                "abs_weight_cost_corr",
                sig_metric("abs_weight_cost_corr", "abs(corr(weight_kg, cost))."),
                preprocess="dog_visits",
                expect=metric4(corr),
                floor=0.9,
                xp=25,
            ),
            step(
                "Linear R²",
                instr(
                    "Fit cost from weight on dog visits.",
                    "Train LinearRegression predicting cost from weight_kg; return training R². Grader applies dog_visits first.",
                    "weight_cost_r2(df)",
                    "R² matches expect/floor.",
                ),
                "Fit a straight-line model of cost from weight and report explained variance.",
                "metric",
                "weight_cost_r2",
                sig_sklearn(
                    "from sklearn.linear_model import LinearRegression",
                    sig_metric("weight_cost_r2", "Train R²."),
                ),
                preprocess="dog_visits",
                expect=metric4(r2),
                floor=0.9,
                xp=35,
            ),
        ],
    )


def m47_fashion() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "sku": list(range(1, 9)),
            "review": [
                "love the fabric great fit",
                "bad quality hate it",
                "great style love color",
                "terrible sizing bad",
                "good purchase love",
                "hate material terrible",
                "good look great",
                "bad experience hate",
            ],
        }
    )
    POS = {"good", "great", "love"}
    NEG = {"bad", "hate", "terrible"}

    def score_text(t: str) -> int:
        toks = t.lower().split()
        return sum(1 for w in toks if w in POS) - sum(1 for w in toks if w in NEG)

    scores = pd.DataFrame({"sku": raw["sku"], "score": [score_text(t) for t in raw["review"]]})
    pos_only = scores[scores["score"] > 0].reset_index(drop=True)
    avg = float(scores["score"].mean())
    return mission(
        name="Fashion review lexicon",
        slug="fashion-review-lexicon",
        scenario="A fashion marketplace wants lexicon sentiment on PDP reviews before escalating to a transformer.",
        track="nlp",
        coverkey="nlp",
        estimateminutes=28,
        brief_md=brief(
            "Fashion review lexicon",
            "Score reviews with POS={good,great,love} and NEG={bad,hate,terrible}.",
            [
                ("sku", "integer", "Product SKU id"),
                ("review", "text", "Customer review text"),
            ],
            ["Return sku,score lexicon net", "Keep positive scores", "Average score overall"],
        ),
        data=raw,
        steps=[
            step(
                "Lexicon scores",
                instr(
                    "Net POS minus NEG word hits per review.",
                    "Tokenize lowercase on whitespace. Return <code>sku,score</code>.",
                    "lexicon_scores(df)",
                    "Scores match the lexicon.",
                ),
                "Reward positive cue words and penalize negative ones, then net them.",
                "frame",
                "lexicon_scores",
                sig_frame("lexicon_scores", "POS/NEG lexicon; return sku,score."),
                expect_csv=csv_of(scores),
                xp=30,
            ),
            step(
                "Positive reviews",
                instr(
                    "Merchandising wants positive-scoring SKUs.",
                    "Keep score > 0. Grader applies lexicon_scores first.",
                    "positive_scores(df)",
                    "Only positive scores remain.",
                ),
                "Keep reviews whose net score is above zero.",
                "frame",
                "positive_scores",
                sig_frame("positive_scores", "Keep score > 0."),
                preprocess="lexicon_scores",
                expect_csv=csv_of(pos_only),
                xp=25,
            ),
            step(
                "Average score",
                instr(
                    "Overall lexicon average.",
                    "Return mean score on the scored frame.",
                    "avg_score(df)",
                    "Average to 4 decimals.",
                ),
                "Average net scores across all reviews.",
                "metric",
                "avg_score",
                sig_metric("avg_score", "Mean score after lexicon_scores."),
                preprocess="lexicon_scores",
                expect=metric4(avg),
                floor=avg - 0.5,
                xp=20,
            ),
        ],
    )


def m48_gaming() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "player_id": list(range(1, 9)),
            "mode": ["solo", "duo", "solo", "duo", "solo", "duo", "solo", "duo"],
            "kills": [2, 8, 1, 10, 5, 7, 0, 12],
            "wins": [0, 1, 0, 1, 0, 1, 0, 1],
        }
    )
    X = raw[["kills"]]
    y = raw["wins"]
    Xtr, Xte, ytr, yte = train_test_split(X, y, test_size=0.25, random_state=0)
    shapes = pd.DataFrame({"n_train": [len(Xtr)], "n_test": [len(Xte)]})
    clf = LogisticRegression()
    clf.fit(Xtr, ytr)
    acc = float(clf.score(Xte, yte))
    return mission(
        name="Gaming win baseline",
        slug="gaming-win-baseline",
        scenario="A game analytics intern must rebuild a logistic win baseline from kill counts.",
        track="ml",
        coverkey="lab",
        estimateminutes=36,
        brief_md=brief(
            "Gaming win baseline",
            "Predict wins from kills with a fixed train/test split.",
            [
                ("player_id", "integer", "Player identifier"),
                ("mode", "text", "Game mode"),
                ("kills", "integer", "Kills in match"),
                ("wins", "0 / 1", "1 if match won"),
            ],
            ["Train/test sizes", "Logistic test accuracy kills→wins"],
        ),
        data=raw,
        steps=[
            step(
                "Split sizes",
                instr(
                    "Document holdout sizes.",
                    "Feature kills, target wins, test_size=0.25, random_state=0. Return <code>n_train,n_test</code>.",
                    "split_shapes(df)",
                    "Counts match.",
                ),
                "Hold out one quarter of matches with a fixed seed.",
                "frame",
                "split_shapes",
                sig_sklearn(
                    "from sklearn.model_selection import train_test_split",
                    sig_frame("split_shapes", "n_train,n_test."),
                ),
                expect_csv=csv_of(shapes),
                xp=25,
            ),
            step(
                "Logistic accuracy",
                instr(
                    "Fit a logistic win baseline.",
                    "Train LogisticRegression on the same split; return test accuracy.",
                    "logistic_accuracy(df)",
                    "Accuracy matches expect/floor.",
                ),
                "Train on training matches and score the held-out matches.",
                "metric",
                "logistic_accuracy",
                sig_sklearn(
                    "from sklearn.model_selection import train_test_split\nfrom sklearn.linear_model import LogisticRegression",
                    sig_metric("logistic_accuracy", "Test accuracy."),
                ),
                expect=metric4(acc),
                floor=min(0.5, acc),
                xp=40,
            ),
        ],
    )


def m49_childcare() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "child_id": list(range(1, 9)),
            "room": ["toddlers", "toddlers", "preschool", "preschool", "toddlers", "preschool", "toddlers", "preschool"],
            "attendance_days": [18, 12, 20, 10, 16, 19, 8, 15],
            "late_pickup": [0, 1, 0, 1, 0, 0, 1, 0],
        }
    )
    regular = raw[raw["attendance_days"] >= 15].reset_index(drop=True)
    by_r = (
        regular.groupby("room", as_index=False)["late_pickup"]
        .mean()
        .rename(columns={"late_pickup": "late_rate"})
        .sort_values("room")
        .reset_index(drop=True)
    )
    rate = float(regular["late_pickup"].mean())
    return mission(
        name="Childcare attendance desk",
        slug="childcare-attendance-desk",
        scenario="A childcare director needs late-pickup rates for regularly attending children.",
        track="eda",
        coverkey="eda",
        estimateminutes=23,
        brief_md=brief(
            "Childcare attendance desk",
            "Regular attendance is 15+ days; review late pickups.",
            [
                ("child_id", "integer", "Child identifier"),
                ("room", "text", "Classroom"),
                ("attendance_days", "integer", "Days attended"),
                ("late_pickup", "0 / 1", "1 if late pickup occurred"),
            ],
            ["Keep regular attendees", "Late rate by room", "Overall late rate on that set"],
        ),
        data=raw,
        steps=[
            step(
                "Regular attendees",
                instr(
                    "Focus on regular attendance.",
                    "Keep attendance_days ≥ 15.",
                    "regular_kids(df)",
                    "Only regular attendees remain.",
                ),
                "Exclude children under fifteen attendance days.",
                "frame",
                "regular_kids",
                sig_frame("regular_kids", "Keep attendance_days >= 15."),
                expect_csv=csv_of(regular),
                xp=25,
            ),
            step(
                "Late by room",
                instr(
                    "Compare rooms.",
                    "Return <code>room,late_rate</code> sorted by room. Grader applies regular_kids first.",
                    "late_rate_by_room(df)",
                    "Rates match.",
                ),
                "Average late-pickup flags within each room.",
                "frame",
                "late_rate_by_room",
                sig_frame("late_rate_by_room", "Return room,late_rate sorted."),
                preprocess="regular_kids",
                expect_csv=csv_of(by_r),
                xp=30,
            ),
            step(
                "Overall late rate",
                instr(
                    "One late rate for the parent newsletter.",
                    "Return mean late_pickup on the filtered frame.",
                    "overall_late_rate(df)",
                    "Rate to 4 decimals.",
                ),
                "Average late flags across remaining children.",
                "metric",
                "overall_late_rate",
                sig_metric("overall_late_rate", "Mean late_pickup after filter."),
                preprocess="regular_kids",
                expect=metric4(rate),
                floor=0.1,
                xp=20,
            ),
        ],
    )


def m50_recycling() -> dict[str, Any]:
    raw = pd.DataFrame(
        {
            "route_id": list(range(1, 9)),
            "zone": ["east", "east", "west", "west", "east", "west", "east", "west"],
            "tons": [12.0, 8.5, 10.0, 6.0, 14.0, 9.0, 7.5, 11.0],
            "contamination": [0.05, 0.12, 0.04, 0.15, 0.06, 0.09, 0.11, 0.03],
        }
    )
    clean = raw[raw["contamination"] <= 0.1].reset_index(drop=True)
    by_z = (
        clean.groupby("zone", as_index=False)["tons"]
        .sum()
        .rename(columns={"tons": "total_tons"})
        .sort_values("zone")
        .reset_index(drop=True)
    )
    avg_c = float(raw["contamination"].mean())
    return mission(
        name="Recycling route desk",
        slug="recycling-route-desk",
        scenario="A city sustainability office needs clean-route tonnage before expanding compost pickup.",
        track="wrangling",
        coverkey="eda",
        estimateminutes=25,
        brief_md=brief(
            "Recycling route desk",
            "Clean routes have contamination ≤ 0.10; roll tons by zone.",
            [
                ("route_id", "integer", "Route identifier"),
                ("zone", "text", "Service zone"),
                ("tons", "number", "Tons collected"),
                ("contamination", "number", "Contamination fraction"),
            ],
            ["Keep clean routes", "Sum tons by zone on that set", "Average contamination overall"],
        ),
        data=raw,
        steps=[
            step(
                "Clean routes",
                instr(
                    "Exclude high-contamination routes.",
                    "Keep contamination ≤ 0.1.",
                    "clean_routes(df)",
                    "Only clean routes remain.",
                ),
                "Drop routes above the ten-percent contamination cap.",
                "frame",
                "clean_routes",
                sig_frame("clean_routes", "Keep contamination <= 0.1."),
                expect_csv=csv_of(clean),
                xp=25,
            ),
            step(
                "Zone tonnage",
                instr(
                    "Compare zones on clean routes.",
                    "Return <code>zone,total_tons</code> sorted by zone. Grader applies clean_routes first.",
                    "tons_by_zone(df)",
                    "Totals match.",
                ),
                "Add tonnage within each zone.",
                "frame",
                "tons_by_zone",
                sig_frame("tons_by_zone", "Return zone,total_tons sorted."),
                preprocess="clean_routes",
                expect_csv=csv_of(by_z),
                xp=30,
            ),
            step(
                "Average contamination",
                instr(
                    "Citywide contamination average.",
                    "Return mean contamination on the raw frame.",
                    "avg_contamination(df)",
                    "Average to 4 decimals.",
                ),
                "Average contamination across all routes.",
                "metric",
                "avg_contamination",
                sig_metric("avg_contamination", "Mean contamination."),
                expect=metric4(avg_c),
                floor=avg_c - 0.05,
                xp=20,
            ),
        ],
    )


# ---------------------------------------------------------------------------
# XML writer
# ---------------------------------------------------------------------------


def build_missions() -> list[dict[str, Any]]:
    builders = [
        m01_retail,
        m02_hr,
        m03_hotels,
        m04_bikes,
        m05_delivery,
        m06_returns,
        m07_energy,
        m08_hospitals,
        m09_marketing,
        m10_inventory,
        m11_weather,
        m12_sports,
        m13_grades,
        m14_banking,
        m15_telecom,
        m16_logistics,
        m17_streaming,
        m18_agriculture,
        m19_airlines,
        m20_real_estate,
        m21_nps,
        m22_tickets,
        m23_manufacturing,
        m24_pharmacy,
        m25_museums,
        m26_rideshare,
        m27_saas,
        m28_libraries,
        m29_parking,
        m30_coffee,
        m31_gym,
        m32_insurance,
        m33_restaurants,
        m34_ecommerce,
        m35_freight,
        m36_cinema,
        m37_utilities,
        m38_campus,
        m39_fleet,
        m40_wellness,
        m41_fundraising,
        m42_callcenter,
        m43_grocery,
        m44_festivals,
        m45_coworking,
        m46_veterinary,
        m47_fashion,
        m48_gaming,
        m49_childcare,
        m50_recycling,
    ]
    return [b() for b in builders]


def _cdata(parent: ET.Element, tag: str, text: str) -> ET.Element:
    el = ET.SubElement(parent, tag)
    el.text = text
    return el


def write_xml(missions: list[dict[str, Any]], path: Path) -> None:
    root = ET.Element("nexcodelab-missions")
    for m in missions:
        node = ET.SubElement(root, "mission")
        for key in (
            "name",
            "slug",
            "scenario",
            "track",
            "coverkey",
            "estimateminutes",
            "status",
        ):
            el = ET.SubElement(node, key)
            el.text = str(m[key])
        for key in ("brief", "starter", "data"):
            _cdata(node, key, m[key])
        steps_el = ET.SubElement(node, "steps")
        for s in m["steps"]:
            step_el = ET.SubElement(steps_el, "step")
            ET.SubElement(step_el, "title").text = s["title"]
            _cdata(step_el, "instructions", s["instructions"])
            ET.SubElement(step_el, "hint").text = s["hint"]
            ET.SubElement(step_el, "checkkind").text = s["checkkind"]
            ET.SubElement(step_el, "fn").text = s["fn"]
            _cdata(step_el, "signature", s["signature"])
            if s.get("preprocess"):
                ET.SubElement(step_el, "preprocess").text = s["preprocess"]
            if s.get("expect_csv") is not None:
                _cdata(step_el, "expect_csv", s["expect_csv"])
            if s.get("expect") is not None:
                ET.SubElement(step_el, "expect").text = s["expect"]
            if s.get("floor") is not None:
                ET.SubElement(step_el, "floor").text = str(s["floor"])
            ET.SubElement(step_el, "xp").text = str(s.get("xp", 25))

    # Serialize with CDATA sections for designated fields.
    cdata_tags = {"brief", "starter", "data", "instructions", "signature", "expect_csv"}

    def serialize(elem: ET.Element, indent: int = 0) -> list[str]:
        pad = "  " * indent
        kids = list(elem)
        if not kids:
            text = elem.text if elem.text is not None else ""
            if elem.tag in cdata_tags:
                return [f"{pad}<{elem.tag}><![CDATA[{text}]]></{elem.tag}>"]
            esc = (
                text.replace("&", "&amp;")
                .replace("<", "&lt;")
                .replace(">", "&gt;")
            )
            return [f"{pad}<{elem.tag}>{esc}</{elem.tag}>"]
        lines = [f"{pad}<{elem.tag}>"]
        for child in kids:
            lines.extend(serialize(child, indent + 1))
        lines.append(f"{pad}</{elem.tag}>")
        return lines

    body = "\n".join(serialize(root))
    path.write_text('<?xml version="1.0" encoding="UTF-8"?>\n' + body + "\n", encoding="utf-8")


def main() -> None:
    missions = build_missions()
    assert len(missions) == 50, len(missions)
    slugs = [m["slug"] for m in missions]
    assert len(slugs) == len(set(slugs)), "duplicate slugs"
    tracks = Counter(m["track"] for m in missions)
    covers = {"lab", "ship", "sales", "clinic", "house", "nlp", "eda"}
    tracks_ok = {"wrangling", "ml", "nlp", "eda"}
    for m in missions:
        assert m["track"] in tracks_ok, m["slug"]
        assert m["coverkey"] in covers, m["slug"]
        assert 20 <= m["estimateminutes"] <= 45, m["slug"]
        assert m["status"] == "published"
        assert m["starter"] == STARTER
        assert 2 <= len(m["steps"]) <= 4, m["slug"]
        rows = m["data"].count("\n")
        assert 6 <= rows <= 12, (m["slug"], rows)
        for s in m["steps"]:
            assert s["checkkind"] in {"frame", "metric"}
            if s["checkkind"] == "frame":
                assert s.get("expect_csv"), m["slug"]
            else:
                assert s.get("expect") is not None or s.get("floor") is not None, m["slug"]
            # Conceptual hints: ban common pandas API names
            banned = ("dropna", "groupby", "mean()", ".copy(", "astype", "to_csv")
            low = s["hint"].lower()
            for b in banned:
                assert b not in low, (m["slug"], s["hint"])

    write_xml(missions, OUT)
    xml_text = OUT.read_text(encoding="utf-8")
    # Well-formed check
    ET.fromstring(xml_text)
    n = xml_text.count("<mission>")
    print(f"Wrote {OUT}")
    print(f"missions: {n}")
    print("track counts:")
    for t, c in sorted(tracks.items()):
        print(f"  {t}: {c}")


if __name__ == "__main__":
    main()
